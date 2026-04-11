<?php

namespace App\Jobs;

use App\Models\DisbursementAttempt;
use App\Models\OutboxEvent;
use App\Models\Partner;
use App\Models\PartnerCorridor;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class ProcessOutboxEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // How many times Laravel queue retries this job itself
    public int $tries = 1;
    // Each run processes a batch — prevents memory bloat
    const BATCH_SIZE = 10;

    // Retry backoff per attempt (seconds)
    // Attempt 1 → 60s, Attempt 2 → 300s, Attempt 3 → 900s, Attempt 4 → final
    const RETRY_DELAYS = [60, 300, 900];

    public function handle(TransactionService $transactionService): void
    {
        $events = OutboxEvent::pending()
            ->lockForUpdate()
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($events as $event) {
            $this->processEvent($event, $transactionService);
        }
    }

    private function processEvent(
        OutboxEvent $event,
        TransactionService $transactionService
    ): void {
        // Mark as processing immediately — prevents other workers grabbing it
        $event->update(['status' => 'processing']);

        try {
            match ($event->event_type) {
                'disbursement_requested' => $this->handleDisbursement($event, $transactionService),
                'refund_requested'       => $this->handleRefund($event, $transactionService),
                'sms_notification'       => $this->handleSms($event),
                'rate_fetch_requested'   => $this->handleRateFetch($event),
                default => throw new \RuntimeException("Unknown event type: {$event->event_type}"),
            };

            $event->update([
                'status'       => 'completed',
                'processed_at' => Carbon::now(),
            ]);

        } catch (\Throwable $e) {
            $this->handleFailure($event, $e, $transactionService);
        }
    }

    // ── Disbursement ─────────────────────────────────────────────────────

    private function handleDisbursement(
        OutboxEvent $event,
        TransactionService $transactionService
    ): void {
        $transaction = Transaction::where('id', $event->payload['transaction_id'])
            ->whereIn('status', ['escrowed', 'processing', 'retrying'])
            ->lockForUpdate()
            ->first();

        if (! $transaction) {
            // Already completed or reversed elsewhere — nothing to do
            $event->update(['status' => 'completed', 'processed_at' => Carbon::now()]);
            return;
        }

        // Select best available partner for this corridor
        $partner = $this->selectPartner(
            $transaction->send_currency,
            $transaction->receive_currency
        );

        if (! $partner) {
            throw new \RuntimeException(
                "No active partner for corridor " .
                "{$transaction->send_currency}-{$transaction->receive_currency}"
            );
        }

        $transaction->update([
            'status'     => 'processing',
            'partner_id' => $partner->id,
        ]);

        // Call partner API
        $attemptNumber = $transaction->disbursement_attempts + 1;
        $startTime     = microtime(true);

        $requestPayload = $this->buildDisbursementPayload($transaction, $partner);

        try {
            $response = Http::timeout($partner->timeout_seconds)
                ->withHeaders($this->buildPartnerHeaders($partner))
                ->post($partner->api_config['disbursement_url'], $requestPayload);

            $responseTimeMs = (int)((microtime(true) - $startTime) * 1000);
            $responseBody   = $response->json();

            // Record the attempt regardless of outcome
            DisbursementAttempt::create([
                'transaction_id'   => $transaction->id,
                'partner_id'       => $partner->id,
                'attempt_number'   => $attemptNumber,
                'request_payload'  => $requestPayload,
                'response_payload' => $responseBody,
                'status'           => $response->successful() ? 'success' : 'failed',
                'response_time_ms' => $responseTimeMs,
                'failure_reason'   => $response->successful() ? null : ($responseBody['message'] ?? 'Unknown'),
                'attempted_at'     => Carbon::now(),
                'responded_at'     => Carbon::now(),
            ]);

            $transaction->increment('disbursement_attempts');
            $transaction->update(['last_attempt_at' => Carbon::now()]);

            if ($response->successful() && isset($responseBody['reference'])) {
                // ✅ Success — complete the transaction
                $transactionService->complete($transaction, $responseBody['reference']);
                return;
            }

            // Partner returned an error
            throw new \RuntimeException(
                "Partner rejected disbursement: " . ($responseBody['message'] ?? $response->status())
            );

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Network timeout — record as timeout attempt
            DisbursementAttempt::create([
                'transaction_id'  => $transaction->id,
                'partner_id'      => $partner->id,
                'attempt_number'  => $attemptNumber,
                'request_payload' => $requestPayload,
                'status'          => 'timeout',
                'failure_reason'  => 'Connection timeout after ' . $partner->timeout_seconds . 's',
                'attempted_at'    => Carbon::now(),
            ]);

            $transaction->increment('disbursement_attempts');
            $transaction->update(['last_attempt_at' => Carbon::now()]);

            throw $e; // bubble up to handleFailure
        }
    }

    // ── Refund ───────────────────────────────────────────────────────────

    private function handleRefund(
        OutboxEvent $event,
        TransactionService $transactionService
    ): void {
        $transaction = Transaction::find($event->payload['transaction_id']);

        if (! $transaction) {
            throw new \RuntimeException("Transaction not found for refund.");
        }

        $transactionService->reverse(
            $transaction,
            $event->payload['reason'] ?? 'Max disbursement attempts exhausted'
        );
    }

    // ── SMS Notification ─────────────────────────────────────────────────

    private function handleSms(OutboxEvent $event): void
    {
        // Placeholder — wire up your SMS provider here (e.g. Africa's Talking)
        $payload = $event->payload;

        Log::info('SMS notification queued', [
            'type'      => $payload['type'] ?? 'unknown',
            'reference' => $payload['reference'] ?? null,
        ]);

        // Example:
        // AfricasTalking::sms()->send([
        //     'to'      => $transaction->sender->phone,
        //     'message' => $this->buildSmsMessage($payload),
        // ]);
    }

    // ── Rate Fetch ───────────────────────────────────────────────────────

    private function handleRateFetch(OutboxEvent $event): void
    {
        // Placeholder — wire up your rate provider here
        Log::info('Rate fetch requested', $event->payload);
    }

    // ── Failure handling ─────────────────────────────────────────────────

    private function handleFailure(
        OutboxEvent $event,
        \Throwable $e,
        TransactionService $transactionService
    ): void {
        $attempts   = $event->attempts + 1;
        $maxReached = $attempts >= $event->max_attempts;

        Log::error('Outbox event failed', [
            'event_id'   => $event->id,
            'event_type' => $event->event_type,
            'attempt'    => $attempts,
            'error'      => $e->getMessage(),
        ]);

        if ($maxReached && $event->event_type === 'disbursement_requested') {
            // Max retries exhausted — queue a refund
            $event->update([
                'status'         => 'failed',
                'attempts'       => $attempts,
                'failure_reason' => $e->getMessage(),
            ]);

            $transaction = Transaction::find($event->payload['transaction_id']);
            if ($transaction) {
                $transaction->update(['status' => 'refund_pending']);

                OutboxEvent::create([
                    'event_type'     => 'refund_requested',
                    'transaction_id' => $transaction->id,
                    'payload' => [
                        'transaction_id' => $transaction->id,
                        'reason'         => 'Max disbursement attempts exhausted: ' . $e->getMessage(),
                    ],
                    'status'          => 'pending',
                    'next_attempt_at' => Carbon::now(),
                ]);
            }
            return;
        }

        // Schedule retry with exponential backoff
        $delaySeconds = self::RETRY_DELAYS[min($attempts - 1, count(self::RETRY_DELAYS) - 1)];

        $event->update([
            'status'          => 'pending',
            'attempts'        => $attempts,
            'failure_reason'  => $e->getMessage(),
            'next_attempt_at' => Carbon::now()->addSeconds($delaySeconds),
        ]);

        // Update transaction status
        if ($event->event_type === 'disbursement_requested') {
            $transaction = Transaction::find($event->payload['transaction_id']);
            $transaction?->update([
                'status'          => 'retrying',
                'next_attempt_at' => Carbon::now()->addSeconds($delaySeconds),
            ]);
        }
    }

    // ── Partner selection ─────────────────────────────────────────────────

    private function selectPartner(string $fromCurrency, string $toCurrency): ?Partner
    {
        // Pick highest priority active partner for this corridor
        $corridor = PartnerCorridor::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('is_active', true)
            ->orderBy('priority')
            ->with('partner')
            ->first();

        return $corridor?->partner?->is_active ? $corridor->partner : null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function buildDisbursementPayload(Transaction $transaction, Partner $partner): array
    {
        return [
            'reference'        => $transaction->reference_number,
            'amount'           => $transaction->receive_amount,
            'currency'         => $transaction->receive_currency,
            'recipient_name'   => $transaction->recipient->full_name,
            'recipient_phone'  => $transaction->recipient->mobile_number,
            'recipient_network'=> $transaction->recipient->mobile_network,
            'payment_method'   => $transaction->recipient->payment_method,
        ];
    }

    private function buildPartnerHeaders(Partner $partner): array
    {
        return [
            'Authorization' => 'Bearer ' . ($partner->api_config['api_key'] ?? ''),
            'Content-Type'  => 'application/json',
            'X-Partner-ID'  => $partner->code,
        ];
    }
}
