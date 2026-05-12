<?php

namespace App\Services\Withdrawals;

use App\Models\Account;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;

class WithdrawalRefundHandler
{
    public function __construct(private LedgerService $ledger) {}

    public function refundPendingStuck(Withdrawal $withdrawal): void
    {
        if ($withdrawal->status !== 'pending') {
            throw new \RuntimeException(
                "Withdrawal {$withdrawal->reference} is not in pending state."
            );
        }

        $this->refundWallet(
            $withdrawal,
            'Auto-recovery: withdrawal stuck in pending state for over 60 minutes'
        );
    }

    public function refundStuck(Withdrawal $withdrawal): void
    {
        if ($withdrawal->status !== 'initiated') {
            throw new \RuntimeException(
                "Withdrawal {$withdrawal->reference} is not in initiated state."
            );
        }

        $this->refundWallet(
            $withdrawal,
            'Auto-recovery: withdrawal stuck in initiated state for over 15 minutes'
        );
    }

    public function refundWallet(Withdrawal $withdrawal, string $reason): void
    {
        DB::transaction(function () use ($withdrawal, $reason) {
            $walletAccount = Account::where('owner_id', $withdrawal->user_id)
                ->where('owner_type', User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $withdrawal->currency_code)
                ->lockForUpdate()
                ->firstOrFail();

            $poolAccount = Account::where('code', "{$withdrawal->currency_code}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

            $this->ledger->post(
                reference:   "WDR-REFUND-{$withdrawal->reference}",
                type:        'adjustment',
                currency:    $withdrawal->currency_code,
                entries: [
                    [
                        'account_id'  => $poolAccount->id,
                        'type'        => 'debit',
                        'amount'      => $withdrawal->amount,
                        'description' => "Withdrawal refund: {$withdrawal->reference}",
                    ],
                    [
                        'account_id'  => $walletAccount->id,
                        'type'        => 'credit',
                        'amount'      => $withdrawal->amount,
                        'description' => "Withdrawal refunded: {$withdrawal->reference}",
                    ],
                ],
                description: "Withdrawal refund: {$withdrawal->reference}"
            );

            $withdrawal->update([
                'status'         => 'failed',
                'failure_reason' => $reason,
                'failed_at'      => now(),
            ]);
        });
    }
}
