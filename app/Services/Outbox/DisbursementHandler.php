<?php

namespace App\Services\Outbox;

use App\Models\Account;
use App\Models\OutboxEvent;
use App\Models\PendingClaim;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Outbox\Contracts\OutboxHandlerInterface;
use App\Services\PartnerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DisbursementHandler implements OutboxHandlerInterface
{
    public function __construct(
        private PartnerService $partnerService,
        private LedgerService  $ledger,
    ) {}

    public function supports(string $eventType): bool
    {
        return $eventType === 'disbursement_requested';
    }

    public function handle(OutboxEvent $event): string
    {
        $transaction = Transaction::findOrFail($event->payload['transaction_id']);

        if (! in_array($transaction->status, ['escrowed', 'processing', 'retrying'])) {
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

        $result = $this->partnerService->disburse($transaction);

        if (! $result->success) {
            $transaction->update([
                'status'         => 'escrowed',
                'failure_reason' => $result->failureReason,
            ]);
            throw new \RuntimeException("Partner disbursement failed: {$result->failureReason}");
        }

        $transaction->update([
            'status'            => 'completed',
            'partner_reference' => $result->partnerReference,
            'completed_at'      => now(),
        ]);

        $this->creditRecipientWallet($transaction);

        OutboxEvent::create([
            'event_type'     => 'sms_notification',
            'transaction_id' => $transaction->id,
            'payload'        => [
                'transaction_id'   => $transaction->id,
                'type'             => 'transfer_completed',
                'reference'        => $transaction->reference_number,
                'receive_amount'   => $transaction->receive_amount,
                'receive_currency' => $transaction->receive_currency,
            ],
            'status' => 'pending',
        ]);

        return "Disbursed {$transaction->reference_number} via partner ref: {$result->partnerReference}";
    }

    public function queueRefundForFailedDisbursement(OutboxEvent $event): void
    {
        $transactionId = $event->payload['transaction_id'] ?? null;

        if (! $transactionId) {
            Log::error('[outbox] Cannot queue refund — no transaction_id in payload', [
                'event_id' => $event->id,
            ]);
            return;
        }

        $transaction = Transaction::find($transactionId);

        if (! $transaction || ! in_array($transaction->status, ['escrowed', 'processing'])) {
            return;
        }

        $transaction->update(['status' => 'failed']);

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

        Log::info('[outbox] Refund queued after max disbursement attempts', [
            'transaction_id' => $transactionId,
            'reference'      => $transaction->reference_number,
        ]);
    }

    private function creditRecipientWallet(Transaction $transaction): void
    {
        $receiveCurrency = $transaction->receive_currency;
        $receiveAmount   = (float) $transaction->receive_amount;
        $reference       = $transaction->reference_number;

        $phoneHash     = hash('sha256', $transaction->recipient->mobile_number);
        $recipientUser = User::where('phone_hash', $phoneHash)->first();

        $poolAccount = Account::where('type', 'system')
            ->where('code', "{$receiveCurrency}-POOL")
            ->firstOrFail();

        $escrowAccount = Account::where('type', 'escrow')
            ->where('currency_code', $receiveCurrency)
            ->firstOrFail();

        if (! $recipientUser) {
            $this->createPendingClaim($transaction, $escrowAccount, $phoneHash);
            return;
        }

        $recipientAccount = Account::where('owner_id', $recipientUser->id)
            ->where('owner_type', User::class)
            ->where('type', 'user_wallet')
            ->where('currency_code', $receiveCurrency)
            ->first();

        if (! $recipientAccount) {
            Log::warning("[outbox] Recipient has no {$receiveCurrency} wallet — holding in escrow", [
                'reference' => $reference,
            ]);
            $this->createPendingClaim($transaction, $escrowAccount, $phoneHash);
            return;
        }

        DB::transaction(function () use (
            $transaction, $poolAccount, $recipientAccount,
            $receiveCurrency, $receiveAmount, $reference, $recipientUser
        ) {
            $this->ledger->post(
                reference:   "TXN-{$reference}-CREDIT",
                type:        'transfer_credit',
                currency:    $receiveCurrency,
                entries: [
                    [
                        'account_id'  => $poolAccount->id,
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
    }

    private function createPendingClaim(
        Transaction $transaction,
        Account     $escrowAccount,
        string      $phoneHash
    ): void {
        $maskedPhone = substr($transaction->recipient->mobile_number, 0, 4)
            . str_repeat('*', max(0, strlen($transaction->recipient->mobile_number) - 7))
            . substr($transaction->recipient->mobile_number, -3);

        PendingClaim::create([
            'transaction_id'         => $transaction->id,
            'recipient_phone_hash'   => $phoneHash,
            'recipient_phone_masked' => $maskedPhone,
            'amount'                 => (float) $transaction->receive_amount,
            'currency_code'          => $transaction->receive_currency,
            'status'                 => 'pending',
            'expires_at'             => now()->addHours(48),
        ]);

        Log::info("[outbox] Recipient not found — PendingClaim created", [
            'reference' => $transaction->reference_number,
            'amount'    => $transaction->receive_amount,
            'currency'  => $transaction->receive_currency,
        ]);
    }
}
