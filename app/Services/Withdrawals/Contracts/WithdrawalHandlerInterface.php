<?php

namespace App\Services\Withdrawals\Contracts;

use App\Models\Withdrawal;
use App\Services\Withdrawals\WithdrawalContext;

interface WithdrawalHandlerInterface
{
    public function supports(WithdrawalContext $ctx): bool;
    public function handle(WithdrawalContext $ctx): Withdrawal;
}
