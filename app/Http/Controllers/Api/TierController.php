<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Services\TierService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TierController extends Controller
{
    public function __construct(private readonly TierService $tierService) {}

    public function show(Request $request): JsonResponse
    {
        $user          = $request->user();
        $tier          = $this->tierService->getTier($user);
        $currency      = $user->wallets()->where('status', 'active')->first()?->currency_code ?? 'MWK';
        $limitCurrency = $tier->limit_currency ?? 'USD';

        // Convert limit from USD to user's wallet currency via ZAR triangulation
        $convertLimit = function (float $amount) use ($limitCurrency, $currency): float {
            if ($limitCurrency === $currency) return $amount;

            $getRate = fn($from, $to) => (float) \App\Models\ExchangeRate::where('from_currency', $from)
                ->where('to_currency', $to)
                ->where('is_active', true)
                ->latest('fetched_at')
                ->value('rate');

            // Try direct rate first (e.g. USD->MWK if it exists)
            $direct = $getRate($limitCurrency, $currency);
            if ($direct > 0) return round($amount * $direct, 2);

            // Triangulate: USD -> ZAR -> target currency
            // USD->ZAR = inverse of ZAR->USD (from SARB)
            $zarToUsd = $getRate('ZAR', $limitCurrency);
            $zarTo    = $getRate('ZAR', $currency);

            if ($zarToUsd > 0 && $zarTo > 0) {
                $usdToZar = 1 / $zarToUsd;
                return round($amount * $usdToZar * $zarTo, 2);
            }

            // Fallback — return unconverted
            \Illuminate\Support\Facades\Log::warning('[TierController] Could not convert limit', [
                'from' => $limitCurrency, 'to' => $currency, 'amount' => $amount
            ]);
            return $amount;
        };

        $perTxLimit   = $convertLimit((float) $tier->per_transaction_limit);
        $dailyLimit   = $convertLimit((float) $tier->daily_limit);
        $monthlyLimit = $convertLimit((float) $tier->monthly_limit);

        $dailyUsed = \App\Models\Transaction::where('sender_id', $user->id)
            ->where('send_currency', $currency)
            ->whereDate('created_at', now()->toDateString())
            ->whereNotIn('status', ['refunded', 'failed'])
            ->sum('send_amount');

        $monthlyUsed = \App\Models\Transaction::where('sender_id', $user->id)
            ->where('send_currency', $currency)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->whereNotIn('status', ['refunded', 'failed'])
            ->sum('send_amount');

        return response()->json([
            'tier'                      => $tier->name,
            'label'                     => $tier->label,
            'currency'                  => $currency,
            'per_transaction_limit'     => $perTxLimit,
            'daily_limit'               => $dailyLimit,
            'daily_used'                => (float) $dailyUsed,
            'daily_remaining'           => max(0, $dailyLimit - (float) $dailyUsed),
            'monthly_limit'             => $monthlyLimit,
            'monthly_used'              => (float) $monthlyUsed,
            'monthly_remaining'         => max(0, $monthlyLimit - (float) $monthlyUsed),
            'fee_discount_percent'      => (float) $tier->fee_discount_percent,
            'referral_discount_percent' => (float) $user->referral_discount_percent,
            'total_discount_percent'    => min((float) $tier->fee_discount_percent + (float) $user->referral_discount_percent, 50),
            'can_upgrade'               => $tier->name !== 'verified',
        ]);
    }

    public function referral(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = $this->tierService->generateReferralCode($user);

        $referrals = Referral::where('referrer_id', $user->id)
            ->with('referred:id,name,created_at')
            ->latest()
            ->get()
            ->map(fn($r) => [
                'name'         => $r->referred->name,
                'status'       => $r->status,
                'joined_at'    => $r->created_at,
                'qualified_at' => $r->qualified_at,
            ]);

        return response()->json([
            'referral_code'    => $code,
            'referral_link'    => 'https://malawipay.netlify.app/register?ref=' . $code,
            'total_referrals'  => $referrals->count(),
            'qualified'        => $referrals->where('status', 'qualified')->count(),
            'your_discount'    => (float) $user->referral_discount_percent,
            'referrals'        => $referrals,
        ]);
    }
}
