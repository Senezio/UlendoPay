<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerCorridor;
use App\Services\RateEngine;
use Illuminate\Http\JsonResponse;

class CorridorController extends Controller
{
    public function __construct(private readonly RateEngine $rateEngine) {}

    public function corridors(): JsonResponse
    {
        $corridors = PartnerCorridor::with('partner')
            ->where('is_active', true)
            ->whereHas('partner', fn($q) => $q->where('is_active', true))
            ->orderBy('priority')
            ->get();

        $available = $corridors
            ->filter(function ($corridor) {
                $rate = $this->rateEngine->getRate(
                    $corridor->from_currency,
                    $corridor->to_currency
                );
                return $rate !== null;
            })
            ->map(function ($corridor) {
                $rate = $this->rateEngine->getRate(
                    $corridor->from_currency,
                    $corridor->to_currency
                );
                return [
                    'from_currency'   => $corridor->from_currency,
                    'to_currency'     => $corridor->to_currency,
                    'min_amount'      => (float) $corridor->min_amount,
                    'max_amount'      => (float) $corridor->max_amount,
                    'fee_percent'     => (float) $corridor->fee_percent,
                    'fee_flat'        => (float) $corridor->fee_flat,
                    'exchange_rate'   => (float) $rate->rate,
                    'rate_expires_at' => $rate->expires_at,
                ];
            })
            ->values();

        $currencies = $available
            ->flatMap(fn($c) => [$c['from_currency'], $c['to_currency']])
            ->unique()
            ->sort()
            ->values();

        return response()->json([
            'corridors'  => $available,
            'currencies' => $currencies,
        ]);
    }
}
