<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\RateLock;
use App\Models\PartnerCorridor;
use App\Services\RateEngine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class RateLockController extends Controller
{
    const LOCK_MINUTES = 15;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_currency' => 'required|string|size:3',
            'to_currency'   => 'required|string|size:3',
            'send_amount'   => 'required|numeric|min:1',
        ]);

        $from = strtoupper($data['from_currency']);
        $to   = strtoupper($data['to_currency']);

        if ($from === $to) {
            // Same currency — use system 1:1 rate, zero fees
            $rate = app(RateEngine::class)->getRate($from, $to);
            $feePercent = 0.0;
            $feeFlat    = 0.0;
        } else {
            // Get the latest active rate for this corridor
            $rate = ExchangeRate::where('from_currency', $from)
                ->where('to_currency', $to)
                ->active()
                ->latest('fetched_at')
                ->first();

            if (!$rate) {
                return response()->json([
                    'message' => "No active exchange rate available for {$from} to {$to}.",
                    'code'    => 'RATE_UNAVAILABLE',
                ], 422);
            }

            // Fetch fee configuration from corridor settings
            $corridor = PartnerCorridor::whereHas('partner', fn($q) => $q->where('is_active', true))
                ->where('from_currency', $from)
                ->where('to_currency', $to)
                ->where('is_active', true)
                ->first();

            if (!$corridor) {
                return response()->json([
                    'message' => "No active corridor available for {$from} to {$to}.",
                    'code'    => 'CORRIDOR_UNAVAILABLE',
                ], 422);
            }

            $feePercent = $corridor->fee_percent;
            $feeFlat    = $corridor->fee_flat;
        }

        // Cancel any existing active lock for this user and corridor
        RateLock::where('user_id', $request->user()->id)
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('status', 'active')
            ->update(['status' => 'expired']);

        $lock = RateLock::create([
            'user_id'          => $request->user()->id,
            'exchange_rate_id' => $rate->id,
            'from_currency'    => $from,
            'to_currency'      => $to,
            'locked_rate'      => $rate->rate,
            'fee_percent'      => $feePercent,
            'fee_flat'         => $feeFlat,
            'status'           => 'active',
            'expires_at'       => Carbon::now()->addMinutes(self::LOCK_MINUTES),
        ]);

        $sendAmount    = (float) $data['send_amount'];
        $feeAmount     = round($sendAmount * ($lock->fee_percent / 100) + $lock->fee_flat, 2);
        $receiveAmount = round(($sendAmount - $feeAmount) * $lock->locked_rate, 2);

        return response()->json([
            'rate_lock' => [
                'id'                 => $lock->id,
                'from_currency'      => $lock->from_currency,
                'to_currency'        => $lock->to_currency,
                'locked_rate'        => $lock->locked_rate,
                'fee_percent'        => $lock->fee_percent,
                'send_amount'        => $sendAmount,
                'fee_amount'         => $feeAmount,
                'receive_amount'     => $receiveAmount,
                'expires_at'         => $lock->expires_at,
                'expires_in_seconds' => Carbon::now()->diffInSeconds($lock->expires_at),
            ],
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $lock = RateLock::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json(['rate_lock' => $lock]);
    }
}