<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\OutboxEvent;
use App\Models\Account;
use App\Models\User;
use App\Models\PendingClaim;
use App\Services\LedgerService;
use App\Models\Transaction;
use App\Services\PartnerService;
use App\Services\RefundService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

class ProcessOutboxEvents extends Command
{
    protected $signature = 'outbox:process {--limit=10}';
    protected $description = 'Process pending outbox events';

    public function __construct(
        private readonly PartnerService $partnerService,
        private readonly RefundService  $refundService,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $limit = (int) $this->option('limit');

        $events = OutboxEvent::where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')
                  ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            $this->line('[outbox] No pending events.');
            return;
        }

        $this->line("[outbox] Processing {$events->count()} event(s).");

        foreach ($events as $event) {
            $this->processEvent($event);
        }
    }

    private function processEvent(OutboxEvent $event): void
    {
        $event->update(['status' => 'processing']);

        try {
            match ($event->event_type) {
                'disbursement_requested'   => $this->handleDisbursement($event),
                'refund_requested'         => $this->handleRefund($event),
                'sms_notification'         => $this->handleSms($event),
                'reconciliation_triggered' => $this->handleReconciliation($event),
                default => throw new \RuntimeException(
                    "Unknown event type: {$event->event_type}"
                ),
            };

            $event->update([
                'status'       => 'completed',
                'processed_at' => now(),
            ]);

            $this->line("[outbox] Event {$event->id} ({$event->event_type}) completed.");

        } catch (\Throwable $e) {
            $permanentErrors = ['PARAMETER_INVALID', 'INVALID_PARAMETER', 'VALIDATION_ERROR'];
            $isPermanent = collect($permanentErrors)->contains(fn($code) => str_contains($e->getMessage(), $code));
            if ($isPermanent) {
                $event->update(['status' => 'failed', 'attempts' => $event->max_attempts, 'failure_reason' => $e->getMessage()]);
                \Illuminate\Support\Facades\Log::error('[outbox] Permanent error - no retry', ['event_id' => $event->id, 'error' => $e->getMessage()]);
                if ($event->event_type === 'disbursement_requested') $this->queueRefundForFailedDisbursement($event);
                return;
            }
            $attempts = $event->attempts + 1;
            $failed   = $attempts >= $event->max_attempts;

            $event->update([
                'status'          => $failed ? 'failed' : 'pending',
                'attempts'        => $attempts,
                'failure_reason'  => $e->getMessage(),
                'next_attempt_at' => $failed
                    ? null
                    : now()->addSeconds(30 * pow(2, $attempts - 1)),
            ]);

            Log::error("[outbox] Event {$event->id} failed", [
                'event_type' => $event->event_type,
                'attempt'    => $attempts,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            $level = $failed ? 'error' : 'warn';
            $this->$level(
                "[outbox] Event {$event->id} failed " .
                "(attempt {$attempts}/{$event->max_attempts}): {$e->getMessage()}"
            );

            // If max attempts reached — queue a refund for escrowed transactions
            if ($failed && $event->event_type === 'disbursement_requested') {
                $this->queueRefundForFailedDisbursement($event);
            }
        }
    }

    private function handleDisbursement(OutboxEvent $event): void
    {
        $transaction = Transaction::findOrFail($event->payload['transaction_id']);

        if (!in_array($transaction->status, ['escrowed', 'processing', 'retrying'])) {
            throw new \RuntimeException(
                "Transaction {$transaction->reference_number} is not in a dispatchable state. " .
                "Current status: {$transaction->status}"
            );
        }

        $transaction->update([
            'status'                => 'processing',
            'last_attempt_at'       => now(),
            'disbursement_attempts' => $transaction->disbursement_attempts + 1,
        ]);

        // Call real partner service — no simulation
        $result = $this->partnerService->disburse($transaction);

        if ($result->success) {
            $transaction->update([
                'status'            => 'completed',
                'partner_reference' => $result->partnerReference,
                'completed_at'      => now(),
            ]);

            $this->line(
                "[outbox] Disbursed {$transaction->reference_number} " .
                "via partner ref: {$result->partnerReference}"
            );

            // ── Credit recipient UlendoPay wallet ────────────────────────
            $this->creditRecipientWallet($transaction);

            // Queue SMS for both sender and recipient
            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => $transaction->id,
                'payload'        => [
                    'transaction_id' => $transaction->id,
                    'type'           => 'transfer_completed',
                    'reference'      => $transaction->reference_number,
                    'receive_amount' => $transaction->receive_amount,
                    'receive_currency' => $transaction->receive_currency,
                ],
                'status' => 'pending',
            ]);

        } else {
            // Partner rejected — put back to escrowed for retry
            $transaction->update([
                'status'         => 'escrowed',
                'failure_reason' => $result->failureReason,
            ]);

            throw new \RuntimeException(
                "Partner disbursement failed: {$result->failureReason}"
            );
        }
    }

    private function handleRefund(OutboxEvent $event): void
    {
        $transaction = Transaction::findOrFail($event->payload['transaction_id']);

        // RefundService handles all validation and atomic reversal
        $this->refundService->refund($transaction);

        $this->line(
            "[outbox] Refunded {$transaction->reference_number} — " .
            "{$transaction->send_amount} {$transaction->send_currency} " .
            "returned to sender."
        );
    }

    private function handleSms(OutboxEvent $event): void
    {
        // SmsService will be built next
        // Resolving it here means it will throw clearly if not yet bound
        app(SmsService::class)->send($event->payload);

        $this->line(
            "[outbox] SMS sent for transaction: " .
            ($event->payload['reference'] ?? $event->payload['transaction_id'])
        );
    }

    private function handleReconciliation(OutboxEvent $event): void
    {
        // ReconciliationService will be built as part of the scheduler
        // Placeholder that fails loudly rather than silently
        throw new \RuntimeException(
            'ReconciliationService not yet implemented. ' .
            'Build it before enabling reconciliation_triggered events.'
        );
    }

    private function creditRecipientWallet(\App\Models\Transaction $transaction): void
    {
        $receiveCurrency = $transaction->receive_currency;
        $receiveAmount   = (float) $transaction->receive_amount;
        $reference       = $transaction->reference_number;

        $phoneHash     = hash('sha256', $transaction->recipient->mobile_number);
        $recipientUser = User::where('phone_hash', $phoneHash)->first();

        $escrowAccount = Account::where('type', 'escrow')
            ->where('currency_code', $receiveCurrency)
            ->firstOrFail();

        if ($recipientUser) {
            $recipientAccount = Account::where('owner_id', $recipientUser->id)
                ->where('owner_type', User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $receiveCurrency)
                ->first();

            if (!$recipientAccount) {
                Log::warning("[outbox] Recipient has no {$receiveCurrency} wallet — holding in escrow", [
                    'reference' => $reference,
                ]);
                $this->holdInEscrowAndCreateClaim($transaction, $escrowAccount, $phoneHash);
                return;
            }

            \Illuminate\Support\Facades\DB::transaction(function () use (
                $transaction, $escrowAccount, $recipientAccount,
                $receiveCurrency, $receiveAmount, $reference, $recipientUser
            ) {
                app(LedgerService::class)->post(
                    reference:   "TXN-{$reference}-CREDIT",
                    type:        'transfer_credit',
                    currency:    $receiveCurrency,
                    entries: [
                        [
                            'account_id'  => $escrowAccount->id,
                            'type'        => 'debit',
                            'amount'      => $receiveAmount,
                            'description' => "Disbursement release: {$reference}",
                        ],
                        [
                            'account_id'  => $recipientAccount->id,
                            'type'        => 'credit',
                            'amount'      => $receiveAmount,
                            'description' => "Transfer received: {$reference}",
                        ],
                    ],
                    description: "Wallet credit after disbursement: {$reference}"
                );

                OutboxEvent::create([
                    'event_type'     => 'sms_notification',
                    'transaction_id' => $transaction->id,
                    'payload'        => [
                        'type'      => 'transfer_received',
                        'phone'     => $recipientUser->phone,
                        'amount'    => $receiveAmount,
                        'currency'  => $receiveCurrency,
                        'reference' => $reference,
                    ],
                    'status'          => 'pending',
                    'next_attempt_at' => now(),
                ]);
            });

            Log::info("[outbox] Recipient wallet credited", [
                'reference' => $reference,
                'amount'    => $receiveAmount,
                'currency'  => $receiveCurrency,
            ]);

        } else {
            $this->holdInEscrowAndCreateClaim($transaction, $escrowAccount, $phoneHash);
        }
    }

    private function holdInEscrowAndCreateClaim(
        \App\Models\Transaction $transaction,
        Account $escrowAccount,
        string $phoneHash
    ): void {
        $receiveCurrency = $transaction->receive_currency;
        $receiveAmount   = (float) $transaction->receive_amount;
        $reference       = $transaction->reference_number;

        $maskedPhone = substr($transaction->recipient->mobile_number, 0, 4)
            . str_repeat('*', max(0, strlen($transaction->recipient->mobile_number) - 7))
            . substr($transaction->recipient->mobile_number, -3);

        PendingClaim::create([
            'transaction_id'         => $transaction->id,
            'recipient_phone_hash'   => $phoneHash,
            'recipient_phone_masked' => $maskedPhone,
            'amount'                 => $receiveAmount,
            'currency_code'          => $receiveCurrency,
            'status'                 => 'pending',
            'expires_at'             => now()->addHours(48),
        ]);

        Log::info("[outbox] Recipient not found — PendingClaim created", [
            'reference' => $reference,
            'amount'    => $receiveAmount,
            'currency'  => $receiveCurrency,
        ]);
    }

    private function queueRefundForFailedDisbursement(OutboxEvent $event): void
    {
        $transactionId = $event->payload['transaction_id'] ?? null;

        if (!$transactionId) {
            Log::error('[outbox] Cannot queue refund — no transaction_id in payload', [
                'event_id' => $event->id,
            ]);
            return;
        }

        $transaction = Transaction::find($transactionId);

        if (!$transaction || !in_array($transaction->status, ['escrowed', 'processing'])) {
            return;
        }

        $transaction->update(['status' => 'failed']);

        // Check no refund already queued
        $alreadyQueued = OutboxEvent::where('event_type', 'refund_requested')
            ->where('transaction_id', $transactionId)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($alreadyQueued) {
            return;
        }

        OutboxEvent::create([
            'event_type'     => 'refund_requested',
            'transaction_id' => $transactionId,
            'payload'        => ['transaction_id' => $transactionId],
            'status'         => 'pending',
        ]);

        $this->line(
            "[outbox] Refund queued for failed transaction: " .
            $transaction->reference_number
        );

        Log::info('[outbox] Refund queued after max disbursement attempts', [
            'transaction_id' => $transactionId,
            'reference'      => $transaction->reference_number,
        ]);
    }
}
