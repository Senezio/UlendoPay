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
        private LedgerService   $ledger,
        private TerraPayPartner $terraPay,
    ) {}

    public function supports(WithdrawalContext $ctx): bool
    {
        return $ctx->isBankTransfer();
    }

    public function handle(WithdrawalContext $ctx): Withdrawal
    {
        $wallet = $ctx->user->wallets()->where('status', 'active')->firstOrFail();

        return DB::transaction(function () use ($ctx, $wallet) {
            $walletAccount = Account::where('owner_id', $ctx->user->id)
                ->where('owner_type', User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $ctx->currency)
                ->lockForUpdate()
                ->firstOrFail();

            $poolAccount = Account::where('code', "{$ctx->currency}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

            $withdrawal = Withdrawal::create([
                'reference'           => Withdrawal::generateReference(),
                'user_id'             => $ctx->user->id,
                'wallet_id'           => $wallet->id,
                'amount'              => $ctx->amount,
                'currency_code'       => $ctx->currency,
                'withdrawal_method'   => 'bank_transfer',
                'provider'            => 'terrapay',
                'bank_account_number' => $ctx->accountNumber,
                'bank_branch_code'    => $ctx->bankCode,
                'bank_name'           => $ctx->accountName,
                'country_code'        => $ctx->countryCode,
                'status'              => 'initiated',
                'initiated_at'        => now(),
            ]);

            $this->ledger->post(
                reference:   "WDR-{$withdrawal->reference}",
                type:        'adjustment',
                currency:    $ctx->currency,
                entries: [
                    [
                        'account_id'  => $walletAccount->id,
                        'type'        => 'debit',
                        'amount'      => $ctx->amount,
                        'description' => "Bank withdrawal: {$withdrawal->reference}",
                    ],
                    [
                        'account_id'  => $poolAccount->id,
                        'type'        => 'credit',
                        'amount'      => $ctx->amount,
                        'description' => "Bank withdrawal held: {$withdrawal->reference}",
                    ],
                ],
                description: "Bank withdrawal initiated: {$withdrawal->reference}"
            );

            OutboxEvent::create([
                'event_type'     => 'disbursement_requested',
                'transaction_id' => null,
                'payload'        => [
                    'type'          => 'bank_withdrawal',
                    'withdrawal_id' => $withdrawal->id,
                    'reference'     => $withdrawal->reference,
                ],
                'status'          => 'pending',
                'next_attempt_at' => now(),
                'max_attempts'    => 3,
            ]);

            AuditLog::create([
                'user_id'     => $ctx->user->id,
                'action'      => 'withdrawal.bank.initiated',
                'entity_type' => 'Withdrawal',
                'entity_id'   => $withdrawal->id,
                'new_values'  => [
                    'reference'    => $withdrawal->reference,
                    'amount'       => $ctx->amount,
                    'currency'     => $ctx->currency,
                    'bank_account' => $ctx->accountNumber,
                    'provider'     => 'terrapay',
                ],
            ]);

            $withdrawal->update(['status' => 'pending']);

            return $withdrawal->fresh();
        });
    }

    public function pollStatus(Withdrawal $withdrawal): void
    {
        if ($withdrawal->status !== 'pending') {
            return;
        }

        if (empty($withdrawal->provider_reference)) {
            throw new \RuntimeException(
                "Withdrawal {$withdrawal->reference} has no provider reference to poll."
            );
        }

        $result = $this->terraPay->checkStatus($withdrawal->provider_reference);

        if ($result->success) {
            DB::transaction(function () use ($withdrawal, $result) {
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
                            'description' => "Bank withdrawal disbursed: {$withdrawal->reference}",
                        ],
                        [
                            'account_id'  => $equityAccount->id,
                            'type'        => 'credit',
                            'amount'      => $withdrawal->amount,
                            'description' => "Bank withdrawal exited system: {$withdrawal->reference}",
                        ],
                    ],
                    description: "Bank withdrawal completion: {$withdrawal->reference}"
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
                    'type'         => 'withdrawal_completed',
                    'phone'        => $withdrawal->user->phone,
                    'amount'       => $withdrawal->amount,
                    'currency'     => $withdrawal->currency_code,
                    'reference'    => $withdrawal->reference,
                    'country_code' => $withdrawal->country_code,
                ],
                'status' => 'pending',
            ]);

        } elseif (str_contains($result->failureReason ?? '', 'pending')) {
            throw new \RuntimeException("Bank withdrawal still pending: {$withdrawal->reference}");
        } else {
            app(WithdrawalRefundHandler::class)->refundWallet(
                $withdrawal,
                $result->failureReason ?? 'TerraPay disbursement failed'
            );
        }
    }
}
