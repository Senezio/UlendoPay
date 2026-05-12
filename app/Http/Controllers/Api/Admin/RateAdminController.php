<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Services\RateEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RateAdminController extends Controller
{
    public function __construct(private RateEngine $rateEngine) {}

    public function rates(): JsonResponse
    {
        $rates = ExchangeRate::where('is_active', true)
            ->orderBy('from_currency')
            ->orderBy('to_currency')
            ->get();

        return response()->json(['rates' => $rates]);
    }

    public function fetchRates(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['message' => 'Only super admins can trigger rate fetches.'], 403);
        }

        try {
            $results = $this->rateEngine->fetchAndStore();

            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'admin.rates.fetched',
                'entity_type' => 'ExchangeRate',
                'entity_id'   => 'manual',
                'new_values'  => $results,
                'ip_address'  => $request->ip(),
            ]);

            return response()->json(['message' => 'Exchange rates updated successfully.', 'results' => $results]);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Rate fetch failed: ' . $e->getMessage()], 500);
        }
    }
}
