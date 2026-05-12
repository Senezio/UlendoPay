<?php

namespace App\Services\Transfers;

use App\Models\RateLock;
use App\Models\Recipient;
use App\Models\User;
use App\Services\TierService;

/**
 * Validates all inputs before a transfer is initiated.
 * Extracted from TransactionService::initiate().
 * Throws RuntimeException on any violation.
 * No DB writes — pure guard logic only.
 */
class TransactionValidator
{
    public function __construct(private TierService $tier) {}

    public function validate(
        User      $sender,
        Recipient $recipient,
        RateLock  $rateLock,
        float     $sendAmount
    ): void {
        $this->tier->checkLimits($sender, $sendAmount, $rateLock->from_currency);

        if ($rateLock->status !== 'active') {
            throw new \RuntimeException('Rate lock is no longer active.');
        }

        if ($rateLock->expires_at->isPast()) {
            throw new \RuntimeException('Rate lock has expired.');
        }

        if ($rateLock->user_id !== $sender->id) {
            throw new \RuntimeException('Rate lock does not belong to this user.');
        }

        if (! $recipient->is_active || $recipient->user_id !== $sender->id) {
            throw new \RuntimeException('Invalid recipient.');
        }
    }
}
