<?php

namespace App\Services\Withdrawals;

use App\Models\Account;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;

class WithdrawalRefundHandler
{
    public function __construct(private LedgerService ) {}

    public function refundPendingStuck(Withdrawal ): void
    {
        if (->status !== pending) {
            throw new \RuntimeException(
                "Withdrawal {->reference} is not in pending state."
            );
        }

        ->refundWallet(
            ,
            Auto-recovery: withdrawal stuck in pending state for over 60 minutes
        );
    }

    public function refundStuck(Withdrawal ): void
    {
        if (->status !== initiated) {
            throw new \RuntimeException(
                "Withdrawal {->reference} is not in initiated state."
            );
        }

        ->refundWallet(
            ,
            Auto-recovery: withdrawal stuck in initiated state for over 15 minutes
        );
    }

    public function refundWallet(Withdrawal , string ): void
    {
        DB::transaction(function () use (, ) {
             = Account::where(owner_id, ->user_id)
                ->where(owner_type, User::class)
                ->where(type, user_wallet)
                ->where(currency_code, ->currency_code)
                ->lockForUpdate()
                ->firstOrFail();

             = Account::where(code, "{->currency_code}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

            ->ledger->post(
                reference:   "WDR-REFUND-{->reference}",
                type:        adjustment,
                currency:    ->currency_code,
                entries: [
                    [
                        account_id  => ->id,
                        type        => debit,
                        amount      => ->amount,
                        description => "Withdrawal refund: {->reference}",
                    ],
                    [
                        account_id  => ->id,
                        type        => credit,
                        amount      => ->amount,
                        description => "Withdrawal refunded: {->reference}",
                    ],
                ],
                description: "Withdrawal refund: {->reference}"
            );

            ->update([
                status         => failed,
                failure_reason => ,
                failed_at      => now(),
            ]);
        });
    }
}
