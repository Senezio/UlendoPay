<?php

namespace App\Services\Withdrawals;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Models\Withdrawal;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalWebhookHandler
{
    private array $completedStatuses = ['completed', 'successful'];
    private array $failedStatuses    = ['failed', 'rejected', 'timed_out', 'expired'];

    public function __construct(
        private LedgerService          $ledger,
        private WithdrawalRefundHandler $refundHandler,
    ) {}

    public function handle(array $payload): void
    {
        $providerRef = $payload['payoutId'] ?? $payload['externalId'] ?? null;
        $status      = $payload['status'] ?? null;

        if (!$providerRef || !$status) {
            Log::warning('[WithdrawalWebhookHandler] Invalid webhook payload', $payload);
            return;
        }

        $withdrawal = Withdrawal::where('provider_reference', $providerRef)
            ->orWhere('reference', $providerRef)
            ->first();

        if (!$withdrawal) {
            Log::error('[WithdrawalWebhookHandler] Withdrawal not found', [
                'provider_reference' => $providerRef,
            ]);
            return;
        }

        if ($withdrawal->isCompleted()) {
            Log::info('[WithdrawalWebhookHandler] Webhook ignored — already completed', [
                'provider_reference' => $providerRef,
            ]);
            return;
        }

        $withdrawal->update(['provider_webhook_payload' => $payload]);

        if (in_array($status, $this->completedStatuses)) {
            $this->markCompleted($withdrawal);
        } elseif (in_array($status, $this->failedStatuses)) {
            $reason = $payload['rejectionReason']['rejectionCode'] ?? $status;
            $this->refundHandler->refundWallet($withdrawal, $reason);

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => null,
                'payload'        => [
                    'type'      => 'withdrawal_failed',
                    'phone'     => $withdrawal->phone_number,
                    'amount'    => $withdrawal->amount,
                    'currency'  => $withdrawal->currency_code,
                    'reference' => $withdrawal->reference,
                    'reason'    => $reason,
                ],
                'status' => 'pending',
            ]);

            Log::warning('[WithdrawalWebhookHandler] Withdrawal failed', [
                'reference' => $withdrawal->reference,
                'reason'    => $reason,
                'provider'  => $withdrawal->provider,
            ]);
        }
    }

    private function markCompleted(Withdrawal $withdrawal): void
    {
        DB::transaction(function () use ($withdrawal) {
            $poolAccount = Account::where('code', "{$withdrawal->currency_code}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

            $equityAccount = Account::where('code', "{$withdrawal->currency_code}-EQUITY")
                ->lockForUpdate()
                ->firstOrFail();

            $this->ledger->post(
                reference:   "WDR-COMPLETE-{$withdrawal->reference}",
                type:        'adjustment',
                currency:    $withdrawal->currency_code,
                entries: [
                    [
                        'account_id'  => $poolAccount->id,
                        'type'        => 'debit',
                        'amount'      => $withdrawal->amount,
                        'description' => "Withdrawal disbursed: {$withdrawal->reference}",
                    ],
                    [
                        'account_id'  => $equityAccount->id,
                        'type'        => 'credit',
                        'amount'      => $withdrawal->amount,
                        'description' => "Withdrawal exited system: {$withdrawal->reference}",
                    ],
                ],
                description: "Withdrawal completion: {$withdrawal->reference}"
            );

            $withdrawal->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);
        });

        OutboxEvent::create([
            'event_type'     => 'sms_notification',
            'transaction_id' => null,
            'payload'        => [
                'type'      => 'withdrawal_completed',
                'phone'     => $withdrawal->phone_number,
                'amount'    => $withdrawal->amount,
                'currency'  => $withdrawal->currency_code,
                'reference' => $withdrawal->reference,
            ],
            'status' => 'pending',
        ]);

        AuditLog::create([
            'user_id'     => $withdrawal->user_id,
            'action'      => 'withdrawal.completed',
            'entity_type' => 'Withdrawal',
            'entity_id'   => $withdrawal->id,
            'new_values'  => [
                'reference' => $withdrawal->reference,
                'amount'    => $withdrawal->amount,
                'currency'  => $withdrawal->currency_code,
                'provider'  => $withdrawal->provider,
            ],
        ]);

        Log::info('[WithdrawalWebhookHandler] Withdrawal completed', [
            'reference' => $withdrawal->reference,
            'provider'  => $withdrawal->provider,
        ]);
    }
}
