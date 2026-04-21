<?php
namespace App\Services;

use App\Models\Referral;
use App\Models\Transaction;
use App\Models\TransferTier;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class TierService
{
    /**
     * Get the effective tier limits for a user.
     */
    public function getTier(User $user): TransferTier
    {
        return TransferTier::where('name', $user->tier)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * Calculate effective fee after tier discount and referral discount.
     */
    public function effectiveFee(User $user, float $feeAmount): float
    {
        $tier            = $this->getTier($user);
        $tierDiscount    = (float) $tier->fee_discount_percent;
        $referralDiscount= (float) $user->referral_discount_percent;
        $totalDiscount   = min($tierDiscount + $referralDiscount, 50); // max 50% discount
        return round($feeAmount * (1 - $totalDiscount / 100), 6);
    }

    /**
     * Convert a limit amount from the tier's limit_currency to the user's send currency.
     * Uses ZAR as the pivot currency via available exchange rates.
     */
    private function convertLimit(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($fromCurrency === $toCurrency) return $amount;

        $rateEngine = app(\App\Services\RateEngine::class);

        // Try direct rate first
        $direct = $rateEngine->getRate($fromCurrency, $toCurrency);
        if ($direct) return round($amount * (float) $direct->rate, 6);

        // Triangulate via ZAR: fromCurrency -> ZAR -> toCurrency
        $toZar   = $rateEngine->getRate($fromCurrency, 'ZAR');
        $zarTo   = $rateEngine->getRate('ZAR', $toCurrency);

        if ($toZar && $zarTo) {
            return round($amount * (float) $toZar->rate * (float) $zarTo->rate, 6);
        }

        // Triangulate via ZAR inverse: USD -> ZAR (via inverse of ZAR->USD)
        $zarToUsd = $rateEngine->getRate('ZAR', $fromCurrency);
        if ($zarToUsd && $zarTo) {
            $usdToZar = 1 / (float) $zarToUsd->rate;
            return round($amount * $usdToZar * (float) $zarTo->rate, 6);
        }

        // Fallback — return unconverted (should not happen in production)
        \Illuminate\Support\Facades\Log::warning('[TierService] Could not convert limit', [
            'from' => $fromCurrency, 'to' => $toCurrency, 'amount' => $amount
        ]);
        return $amount;
    }

    /**
     * Check if a transaction amount is within user's tier limits.
     * Limits are stored in limit_currency (default USD) and converted to send currency.
     * Throws RuntimeException if limit exceeded.
     */
    public function checkLimits(User $user, float $amount, string $currency): void
    {
        $tier         = $this->getTier($user);
        $limitCurrency = $tier->limit_currency ?? 'USD';

        // Convert limits to user's send currency
        $perTxLimit    = $this->convertLimit((float) $tier->per_transaction_limit, $limitCurrency, $currency);
        $dailyLimit    = $this->convertLimit((float) $tier->daily_limit,            $limitCurrency, $currency);
        $monthlyLimit  = $this->convertLimit((float) $tier->monthly_limit,          $limitCurrency, $currency);

        // Per transaction limit
        if ($amount > $perTxLimit) {
            throw new \RuntimeException(
                "Amount exceeds your per-transaction limit of {$currency} " .
                number_format($perTxLimit, 2) .
                ". Please verify your identity to increase your limit."
            );
        }

        $today = Carbon::now()->toDateString();

        // Daily limit
        $dailyTotal = Transaction::where('sender_id', $user->id)
            ->where('send_currency', $currency)
            ->whereDate('created_at', $today)
            ->whereNotIn('status', ['refunded', 'failed'])
            ->sum('send_amount');

        if (($dailyTotal + $amount) > $dailyLimit) {
            throw new \RuntimeException(
                "This transfer would exceed your daily limit of {$currency} " .
                number_format($dailyLimit, 2) .
                ". Verify your identity to increase your limit."
            );
        }

        // Monthly limit
        $monthlyTotal = Transaction::where('sender_id', $user->id)
            ->where('send_currency', $currency)
            ->whereYear('created_at', Carbon::now()->year)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereNotIn('status', ['refunded', 'failed'])
            ->sum('send_amount');

        if (($monthlyTotal + $amount) > $monthlyLimit) {
            throw new \RuntimeException(
                "This transfer would exceed your monthly limit of {$currency} " .
                number_format($monthlyLimit, 2) .
                ". Verify your identity to increase your limit."
            );
        }
    }

    /**
     * Upgrade user tier based on KYC status.
     */
    public function syncTier(User $user, ?string $targetTier = null): void
    {
        $tier = $targetTier ?? match($user->kyc_status) {
            'verified' => 'verified',
            'pending'  => 'basic',
            default    => 'unverified',
        };

        if ($user->tier !== $tier) {
            $user->update(['tier' => $tier]);
        }
    }

    /**
     * Generate a unique referral code for a user.
     */
    public function generateReferralCode(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }

        do {
            $code = strtoupper(Str::random(8));
        } while (User::where('referral_code', $code)->exists());

        $user->update(['referral_code' => $code]);
        return $code;
    }

    /**
     * Apply referral code during registration.
     */
    public function applyReferral(User $newUser, string $referralCode): void
    {
        $referrer = User::where('referral_code', $referralCode)
            ->where('status', 'active')
            ->first();

        if (!$referrer || $referrer->id === $newUser->id) {
            return;
        }

        // Link referral
        $newUser->update([
            'referred_by'               => $referrer->id,
            'referral_discount_percent' => 5, // 5% fee discount for referred user
        ]);

        // Create referral record
        Referral::create([
            'referrer_id'               => $referrer->id,
            'referred_id'               => $newUser->id,
            'status'                    => 'pending',
            'referrer_discount_percent' => 5, // referrer gets 5% too once qualified
            'referred_discount_percent' => 5,
        ]);
    }

    /**
     * Qualify referral after referred user completes first transaction.
     */
    public function qualifyReferral(User $user): void
    {
        $referral = Referral::where('referred_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (!$referral) return;

        $referral->update([
            'status'       => 'qualified',
            'qualified_at' => now(),
        ]);

        // Apply discount to referrer
        $referral->referrer->increment('referral_discount_percent', 5);
    }

    /**
     * Get fee calculator preview (public — no auth required).
     */
    public function calculateFee(
        float  $amount,
        string $fromCurrency,
        string $toCurrency,
        ?User  $user = null
    ): array {
        // Get rate from exchange rates table
        $rate = \App\Models\ExchangeRate::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('is_active', true)
            ->latest('fetched_at')
            ->first();

        if (!$rate) {
            throw new \RuntimeException("No rate available for {$fromCurrency} to {$toCurrency}.");
        }

        // Get corridor fee
        $corridor = \App\Models\PartnerCorridor::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();

        if (!$corridor) {
            throw new \RuntimeException("No active corridor found for {$fromCurrency} to {$toCurrency}.");
        }

        $feePercent = (float) $corridor->fee_percent;
        $feeFlat    = (float) $corridor->fee_flat;
        $feeAmount  = round($amount * ($feePercent / 100) + $feeFlat, 6);

        // Apply user discount if logged in
        $discountPercent = 0;
        if ($user) {
            $tier            = $this->getTier($user);
            $discountPercent = min(
                (float) $tier->fee_discount_percent + (float) $user->referral_discount_percent,
                50
            );
            $feeAmount = round($feeAmount * (1 - $discountPercent / 100), 6);
        }

        $netAmount    = $amount - $feeAmount;
        $receiveAmount = round($netAmount * (float) $rate->rate, 6);

        return [
            'from_currency'    => $fromCurrency,
            'to_currency'      => $toCurrency,
            'send_amount'      => $amount,
            'fee_amount'       => $feeAmount,
            'fee_percent'      => $feePercent,
            'net_amount'       => $netAmount,
            'exchange_rate'    => (float) $rate->rate,
            'receive_amount'   => $receiveAmount,
            'discount_percent' => $discountPercent,

        ];
    }
}
