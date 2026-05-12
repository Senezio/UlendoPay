<?php

namespace App\Services\Transfers;

use App\Models\RateLock;
use App\Models\Recipient;
use App\Models\User;

/**
 * Immutable value object passed to every TransferHandler.
 * Built once inside TransactionService::initiate() after validation.
 * Handlers read from it — they never mutate it.
 *
 * resolvedRecipientUser is pre-fetched once during context build
 * to avoid repeated DB lookups across supports() and handle() calls.
 * Null means the recipient is not a registered UlendoPay user.
 */
readonly class TransactionContext
{
    public function __construct(
        public User      $sender,
        public Recipient $recipient,
        public RateLock  $rateLock,
        public float     $sendAmount,
        public float     $receiveAmount,
        public float     $feeAmount,
        public float     $guaranteeAmount,
        public float     $escrowAmount,
        public string    $sendCurrency,
        public string    $receiveCurrency,
        public float     $lockedRate,
        public bool      $isSameCurrency,
        public string    $reference,
        public ?User     $resolvedRecipientUser = null,
    ) {}
}
