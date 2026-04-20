<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TierService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CalculatorController extends Controller
{
    public function __construct(private readonly TierService $tierService) {}

    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_currency' => 'required|string|size:3',
            'to_currency'   => 'required|string|size:3',
            'amount'        => 'required|numeric|min:1',
        ]);

        try {
            $user   = auth('sanctum')->user();
            $result = $this->tierService->calculateFee(
                amount:       (float) $data['amount'],
                fromCurrency: strtoupper($data['from_currency']),
                toCurrency:   strtoupper($data['to_currency']),
                user:         $user
            );

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
