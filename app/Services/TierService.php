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
     * Check if a transaction amount is within user's tier limits.
     * Throws RuntimeException if limit exceeded.
     */
    public function checkLimits(User $user, float $amount, string $currency): void
    {
        $tier = $this->getTier($user);

        // Per transaction limit
        if ($amount > (float) $tier->per_transaction_limit) {
            throw new \RuntimeException(
                "Amount exceeds your per-transaction limit of {$currency} " .
                number_format($tier->per_transaction_limit, 2) .
                ". Please verify your identity to increase your limit."
            );
        }

        $today     = Carbon::now()->toDateString();
        $thisMonth = Carbon::now()->format('Y-m');

        // Daily limit
        $dailyTotal = Transaction::where('sender_id', $user->id)
            ->where('send_currency', $currency)
            ->whereDate('created_at', $today)
            ->whereNotIn('status', ['refunded', 'failed'])
            ->sum('send_amount');

        if (($dailyTotal + $amount) > (float) $tier->daily_limit) {
            throw new \RuntimeException(
                "This transfer would exceed your daily limit of {$currency} " .
                number_format($tier->daily_limit, 2) .
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

        if (($monthlyTotal + $amount) > (float) $tier->monthly_limit) {
            throw new \RuntimeException(
                "This transfer would exceed your monthly limit of {$currency} " .
                number_format($tier->monthly_limit, 2) .
                ". Verify your identity to increase your limit."
            );
        }
    }

    /**
     * Upgrade user tier based on KYC status.
     */
    public function syncTier(User $user): void
    {
        $tier = match($user->kyc_status) {
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

        $feePercent = $corridor ? (float) $corridor->fee_percent : 1.5;
        $feeFlat    = $corridor ? (float) $corridor->fee_flat : 0;
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
            'rate_source'      => $rate->source ?? 'central_bank',
        ];
    }
}
