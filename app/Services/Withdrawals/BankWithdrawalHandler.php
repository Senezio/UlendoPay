<?php

namespace App\Services\Withdrawals;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\LedgerService;
use App\Services\Partners\TerraPayPartner;
use App\Services\Withdrawals\Contracts\WithdrawalHandlerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BankWithdrawalHandler implements WithdrawalHandlerInterface
{
    public function __construct(
        private LedgerService  ,
        private TerraPayPartner ,
    ) {}

    public function supports(WithdrawalContext ): bool
    {
        return ->isBankTransfer();
    }

    public function handle(WithdrawalContext ): Withdrawal
    {
         = ->user->wallets()->where(status, active)->firstOrFail();

        return DB::transaction(function () use (, ) {
             = Account::where(owner_id, ->user->id)
                ->where(owner_type, User::class)
                ->where(type, user_wallet)
                ->where(currency_code, ->currency)
                ->lockForUpdate()
                ->firstOrFail();

             = Account::where(code, "{->currency}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

             = Withdrawal::create([
                reference           => Withdrawal::generateReference(),
                user_id             => ->user->id,
                wallet_id           => ->id,
                amount              => ->amount,
                currency_code       => ->currency,
                withdrawal_method   => bank_transfer,
                provider            => terrapay,
                bank_account_number => ->bankAccountNumber,
                bank_branch_code    => ->bankBranchCode,
                bank_name           => ->bankName,
                country_code        => ->countryCode,
                status              => initiated,
                initiated_at        => now(),
            ]);

            ->ledger->post(
                reference:   "WDR-{->reference}",
                type:        adjustment,
                currency:    ->currency,
                entries: [
                    [
                        account_id  => ->id,
                        type        => debit,
                        amount      => ->amount,
                        description => "Bank withdrawal: {->reference}",
                    ],
                    [
                        account_id  => ->id,
                        type        => credit,
                        amount      => ->amount,
                        description => "Bank withdrawal held: {->reference}",
                    ],
                ],
                description: "Bank withdrawal initiated: {->reference}"
            );

            OutboxEvent::create([
                event_type     => disbursement_requested,
                transaction_id => null,
                payload        => [
                    type          => bank_withdrawal,
                    withdrawal_id => ->id,
                    reference     => ->reference,
                ],
                status          => pending,
                next_attempt_at => now(),
                max_attempts    => 3,
            ]);

            AuditLog::create([
                user_id     => ->user->id,
                action      => withdrawal.bank.initiated,
                entity_type => Withdrawal,
                entity_id   => ->id,
                new_values  => [
                    reference    => ->reference,
                    amount       => ->amount,
                    currency     => ->currency,
                    bank_account => ->bankAccountNumber,
                    provider     => terrapay,
                ],
            ]);

            ->update([status => pending]);

            return ->fresh();
        });
    }

    public function pollStatus(Withdrawal ): void
    {
        if (->status !== pending) {
            return;
        }

        if (empty(->provider_reference)) {
            throw new \RuntimeException(
                "Withdrawal {->reference} has no provider reference to poll."
            );
        }

         = ->terraPay->checkStatus(->provider_reference);

        if (->success) {
            DB::transaction(function () use () {
                 = Account::where(code, "{->currency_code}-POOL")
                    ->lockForUpdate()
                    ->firstOrFail();

                 = Account::where(code, "{->currency_code}-EQUITY")
                    ->lockForUpdate()
                    ->firstOrFail();

                ->ledger->post(
                    reference:   "WDR-COMPLETE-{->reference}",
                    type:        adjustment,
                    currency:    ->currency_code,
                    entries: [
                        [
                            account_id  => ->id,
                            type        => debit,
                            amount      => ->amount,
                            description => "Bank withdrawal disbursed: {->reference}",
                        ],
                        [
                            account_id  => ->id,
                            type        => credit,
                            amount      => ->amount,
                            description => "Bank withdrawal exited system: {->reference}",
                        ],
                    ],
                    description: "Bank withdrawal completion: {->reference}"
                );

                ->update([
                    status       => completed,
                    completed_at => now(),
                ]);
            });

            OutboxEvent::create([
                event_type     => sms_notification,
                transaction_id => null,
                payload        => [
                    type         => withdrawal_completed,
                    phone        => ->user->phone,
                    amount       => ->amount,
                    currency     => ->currency_code,
                    reference    => ->reference,
                    country_code => ->country_code,
                ],
                status => pending,
            ]);

        } elseif (str_contains(->failureReason ?? , pending)) {
            throw new \RuntimeException("Bank withdrawal still pending: {->reference}");
        } else {
            app(WithdrawalRefundHandler::class)->refundWallet(
                ,
                ->failureReason ?? TerraPay disbursement failed
            );
        }
    }
}
