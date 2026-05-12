<?php

namespace App\Services\Withdrawals;

use App\Models\User;

final class WithdrawalContext
{
    public function __construct(
        public readonly User $user,
        public readonly string $currency,
        public readonly float $amount,
        public readonly string $method,           // 'mobile_money' | 'bank_transfer'
        public readonly ?string $phoneNumber = null,
        public readonly ?string $mobileOperator = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $bankCode = null,
        public readonly ?string $accountNumber = null,
        public readonly ?string $accountName = null,
    ) {}

    public function isMobileMoney(): bool
    {
        return $this->method === 'mobile_money';
    }

    public function isBankTransfer(): bool
    {
        return $this->method === 'bank_transfer';
    }
}
