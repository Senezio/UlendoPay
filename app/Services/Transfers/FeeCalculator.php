<?php

namespace App\Services\Transfers;

use App\Models\RateLock;
use App\Services\TierService;

/**
 * Responsible for all fee and guarantee calculations.
 * Extracted from TransactionService private helpers.
 * Pure calculation — no DB writes, no side effects.
 */
class FeeCalculator
{
    public function __construct(private TierService $tier) {}

    /**
     * Raw fee before tier discount: percent + flat.
     */
    public function rawFee(float $amount, RateLock $rateLock): float
    {
        $percentFee = round($amount * ($rateLock->fee_percent / 100), 6);
        return round($percentFee + $rateLock->fee_flat, 6);
    }

    /**
     * Effective fee after applying sender's tier discount.
     */
    public function effectiveFee(float $amount, RateLock $rateLock, $sender): float
    {
        $raw = $this->rawFee($amount, $rateLock);
        return $this->tier->effectiveFee($sender, $raw);
    }

    /**
     * Guarantee contribution for this transfer.
     * Uses rateLock value if present, falls back to corridor config.
     */
    public function guarantee(
        float   $amount,
        string  $fromCurrency,
        string  $toCurrency,
        ?float  $guaranteePercent = null
    ): float {
        if ($guaranteePercent !== null) {
            return round($amount * $guaranteePercent, 6);
        }

        $corridor = \App\Models\PartnerCorridor::whereHas(
            'partner', fn($q) => $q->where('is_active', true)
        )
            ->where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('is_active', true)
            ->first();

        return round($amount * ($corridor?->guarantee_percent ?? 0.005), 6);
    }
}
