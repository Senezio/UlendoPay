<?php
namespace App\Services;

use App\Models\Referral;
use App\Models\Transaction;
use App\Models\TransferTier;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TierService
{
    /**
     * Get the effective tier for a user by matching their tier name.
     * Falls back to the lowest active tier if no match found.
     */
    public function getTier(User $user): TransferTier
    {
        return TransferTier::where('name', $user->tier)
            ->where('is_active', true)
            ->first()
            ?? TransferTier::where('is_active', true)
                ->orderBy('level')
                ->firstOrFail();
    }

    /**
     * Get tier by level — level-based lookup, no hardcoded names.
     */
    public function getTierByLevel(int $level): ?TransferTier
    {
        return TransferTier::where('level', $level)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get the default entry-level tier (lowest level).
     */
    public function getDefaultTier(): TransferTier
    {
        return TransferTier::where('is_active', true)
            ->orderBy('level')
            ->firstOrFail();
    }

    /**
     * Calculate effective fee after tier discount and referral discount.
     */
    public function effectiveFee(User $user, float $feeAmount): float
    {
        $tier             = $this->getTier($user);
        $tierDiscount     = (float) $tier->fee_discount_percent;
        $referralDiscount = (float) $user->referral_discount_percent;
        $totalDiscount    = min($tierDiscount + $referralDiscount, 50);
        return (float) bcmul((string)$feeAmount, bcsub('1', bcdiv((string)$totalDiscount, '100', 10), 10), 6);
    }

    /**
     * Convert a limit amount from the tier's limit_currency to the user's send currency.
     * Delegates entirely to RateEngine which handles direct lookup and USD chaining.
     */
    public function convertLimit(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($fromCurrency === $toCurrency) return $amount;

        $rate = app(\App\Services\RateEngine::class)->getRate($fromCurrency, $toCurrency);

        if ($rate) return (float) bcmul((string)$amount, (string)$rate->rate, 6);

        Log::warning('[TierService] Could not convert limit', [
            'from' => $fromCurrency, 'to' => $toCurrency, 'amount' => $amount,
        ]);
        return $amount;
    }

    /**
     * Check if a transaction amount is within user's tier limits.
     */
    public function checkLimits(User $user, float $amount, string $currency): void
    {
        $tier          = $this->getTier($user);
        $limitCurrency = $tier->limit_currency ?? 'USD';

        $perTxLimit   = $this->convertLimit((float) $tier->per_transaction_limit, $limitCurrency, $currency);
        $dailyLimit   = $this->convertLimit((float) $tier->daily_limit,            $limitCurrency, $currency);
        $monthlyLimit = $this->convertLimit((float) $tier->monthly_limit,          $limitCurrency, $currency);

        if ($amount > $perTxLimit) {
            throw new \RuntimeException(
                "Amount exceeds your per-transaction limit of {$currency} " .
                number_format($perTxLimit, 2) .
                ". Please verify your identity to increase your limit."
            );
        }

        $today = Carbon::now()->toDateString();

        $dailyTotal = Transaction::where('sender_id', $user->id)
            ->where('send_currency', $currency)
            ->whereDate('created_at', $today)
            ->whereNotIn('status', ['refunded', 'failed'])
            ->sum('send_amount');

        if (bccomp(bcadd((string)$dailyTotal, (string)$amount, 6), (string)$dailyLimit, 6) > 0) {
            throw new \RuntimeException(
                "This transfer would exceed your daily limit of {$currency} " .
                number_format($dailyLimit, 2) .
                ". Verify your identity to increase your limit."
            );
        }

        $monthlyTotal = Transaction::where('sender_id', $user->id)
            ->where('send_currency', $currency)
            ->whereYear('created_at', Carbon::now()->year)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereNotIn('status', ['refunded', 'failed'])
            ->sum('send_amount');

        if (bccomp(bcadd((string)$monthlyTotal, (string)$amount, 6), (string)$monthlyLimit, 6) > 0) {
            throw new \RuntimeException(
                "This transfer would exceed your monthly limit of {$currency} " .
                number_format($monthlyLimit, 2) .
                ". Verify your identity to increase your limit."
            );
        }
    }

    /**
     * Sync user tier based on KYC status or an explicit target tier name.
     * Uses level-based ordering — never hardcodes tier names.
     */
    public function syncTier(User $user, ?string $targetTierName = null): void
    {
        if ($targetTierName) {
            // Explicit tier name — validate it exists and is active
            $tier = TransferTier::where('name', $targetTierName)
                ->where('is_active', true)
                ->first();

            if (!$tier) {
                Log::warning('[TierService] syncTier: target tier not found', [
                    'user_id'     => $user->id,
                    'target_tier' => $targetTierName,
                ]);
                return;
            }

            if ($user->tier !== $tier->name) {
                $user->update(['tier' => $tier->name]);
                Log::info('[TierService] User tier updated', [
                    'user_id'  => $user->id,
                    'old_tier' => $user->tier,
                    'new_tier' => $tier->name,
                ]);
            }
            return;
        }

        // Auto-assign based on KYC status using level ordering — no hardcoded names
        $allTiers = TransferTier::where('is_active', true)->orderBy('level')->get();

        if ($allTiers->isEmpty()) {
            Log::warning('[TierService] No active tiers found — cannot sync user tier', [
                'user_id' => $user->id,
            ]);
            return;
        }

        $tier = match($user->kyc_status) {
            'verified' => $allTiers->last(),   // highest level tier
            'pending'  => $allTiers->count() > 1 ? $allTiers->get(1) : $allTiers->first(), // second tier
            default    => $allTiers->first(),  // lowest level tier
        };

        if ($tier && $user->tier !== $tier->name) {
            $user->update(['tier' => $tier->name]);
            Log::info('[TierService] User tier auto-synced', [
                'user_id'    => $user->id,
                'kyc_status' => $user->kyc_status,
                'new_tier'   => $tier->name,
            ]);
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

        $newUser->update([
            'referred_by'               => $referrer->id,
            'referral_discount_percent' => 5,
        ]);

        Referral::create([
            'referrer_id'               => $referrer->id,
            'referred_id'               => $newUser->id,
            'status'                    => 'pending',
            'referrer_discount_percent' => 5,
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
        $rate = app(\App\Services\RateEngine::class)->getRate($fromCurrency, $toCurrency);

        if (!$rate) {
            throw new \RuntimeException("No rate available for {$fromCurrency} to {$toCurrency}.");
        }

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
        $feeAmount  = bcadd(bcmul((string)$amount, bcdiv((string)$feePercent, '100', 10), 6), (string)$feeFlat, 6);

        $discountPercent = 0;
        if ($user) {
            $tier            = $this->getTier($user);
            $discountPercent = min(
                (float) bcadd((string)$tier->fee_discount_percent, (string)$user->referral_discount_percent, 6),
                50
            );
            $feeAmount = bcmul((string)$feeAmount, bcsub('1', bcdiv((string)$discountPercent, '100', 10), 10), 6);
        }

        $netAmount     = bcsub((string)$amount, (string)$feeAmount, 6);
        $receiveAmount = bcmul((string)$netAmount, (string)$rate->rate, 6);

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
