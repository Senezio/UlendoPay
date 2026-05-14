<?php

namespace App\Services;

use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Withdrawals\BankWithdrawalHandler;
use App\Services\Withdrawals\Contracts\WithdrawalHandlerInterface;
use App\Services\Withdrawals\MobileMoneyWithdrawalHandler;
use App\Services\Withdrawals\WithdrawalContext;
use App\Services\Withdrawals\WithdrawalRefundHandler;
use App\Services\Withdrawals\WithdrawalWebhookHandler;

class WithdrawalService
{
    private array $handlers;

    public function __construct(
        private MobileMoneyWithdrawalHandler $mobileMoneyHandler,
        private BankWithdrawalHandler        $bankHandler,
        private WithdrawalWebhookHandler     $webhookHandler,
        private WithdrawalRefundHandler      $refundHandler,
    ) {
        $this->handlers = [
            $this->mobileMoneyHandler,
            $this->bankHandler,
        ];
    }

    public function initiate(
        User   $user,
        string $phoneNumber,
        string $mobileOperator,
        float  $amount
    ): Withdrawal {
        $wallet = $user->wallets()->where('status', 'active')->firstOrFail();

        $this->guardBalance($user, $wallet->currency_code, $amount);

        $context = new WithdrawalContext(
            user:           $user,
            currency:       $wallet->currency_code,
            amount:         $amount,
            method:         'mobile_money',
            phoneNumber:    $phoneNumber,
            mobileOperator: $mobileOperator,
            countryCode:    $user->country_code ?? $this->currencyToCountry($wallet->currency_code),
        );

        return $this->resolveHandler($context)->handle($context);
    }

    public function initiateBank(
        User   $user,
        string $bankAccountNumber,
        string $bankBranchCode,
        string $bankName,
        string $countryCode,
        float  $amount
    ): Withdrawal {
        $wallet = $user->wallets()->where('status', 'active')->firstOrFail();

        $this->guardBalance($user, $wallet->currency_code, $amount);

        $context = new WithdrawalContext(
            user:              $user,
            currency:          $wallet->currency_code,
            amount:            $amount,
            method:            'bank_transfer',
            bankAccountNumber: $bankAccountNumber,
            bankBranchCode:    $bankBranchCode,
            bankName:          $bankName,
            countryCode:       $countryCode,
        );

        return $this->resolveHandler($context)->handle($context);
    }

    public function handleWebhook(array $payload): void
    {
        $this->webhookHandler->handle($payload);
    }

    public function refundStuck(Withdrawal $withdrawal): void
    {
        $this->refundHandler->refundStuck($withdrawal);
    }

    public function refundPendingStuck(Withdrawal $withdrawal): void
    {
        $this->refundHandler->refundPendingStuck($withdrawal);
    }

    public function getSupportedOperators(string $currency): array
    {
        return collect(config('services.pawapay.correspondents', []))
            ->keys()
            ->filter(fn($key) => str_starts_with($key, "{$currency}:"))
            ->map(fn($key) => explode(':', $key)[1])
            ->values()
            ->toArray();
    }

    private function resolveHandler(WithdrawalContext $context): WithdrawalHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($context)) {
                return $handler;
            }
        }

        throw new \RuntimeException(
            "No handler found for withdrawal method: {$context->method}"
        );
    }

    private function guardBalance(User $user, string $currency, float $amount): void
    {
        if ($amount < 1) {
            throw new \RuntimeException("Minimum withdrawal amount is 1 {$currency}.");
        }

        $account = \App\Models\Account::where('owner_id', $user->id)
            ->where('owner_type', User::class)
            ->where('type', 'user_wallet')
            ->where('currency_code', $currency)
            ->firstOrFail();

        $balance = (float) ($account->balance?->balance ?? 0);

        if ($amount > $balance) {
            throw new \RuntimeException(
                "Insufficient balance. Available: {$currency} " . number_format($balance, 2)
            );
        }
    }

    private function currencyToCountry(string $currency): string
    {
        $map = config('services.currency_country_map', []);

        if (isset($map[$currency])) {
            return $map[$currency];
        }

        throw new \InvalidArgumentException("Unsupported currency: {$currency}");
    }
}
