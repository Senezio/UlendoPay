<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reporting\BalanceSheetService;
use App\Services\Reporting\CashFlowService;
use App\Services\Reporting\ProfitLossService;
use App\Services\Reporting\TrialBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Financial Statements API
 *
 * All endpoints support:
 *   ?currency=MWK          Filter by currency (optional, returns all currencies if omitted)
 *
 * Balance Sheet / Trial Balance also support:
 *   ?as_of=2024-12-31      Point-in-time snapshot (optional, defaults to current)
 *
 * P&L / Cash Flow require a date range:
 *   ?from=2024-01-01&to=2024-12-31
 */
class ReportingController extends Controller
{
    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly BalanceSheetService $balanceSheet,
        private readonly ProfitLossService   $profitLoss,
        private readonly CashFlowService     $cashFlow,
    ) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'as_of'    => ['nullable', 'date'],
        ]);

        $report = $this->trialBalance->generate(
            currency: $validated['currency'] ?? null,
            asOf:     $validated['as_of'] ?? null,
        );

        return response()->json([
            'success' => true,
            'report'  => $report,
        ]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'as_of'    => ['nullable', 'date'],
        ]);

        $report = $this->balanceSheet->generate(
            currency: $validated['currency'] ?? null,
            asOf:     $validated['as_of'] ?? null,
        );

        return response()->json([
            'success' => true,
            'report'  => $report,
        ]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'     => ['required', 'date'],
            'to'       => ['required', 'date', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $report = $this->profitLoss->generate(
            from:     $validated['from'],
            to:       $validated['to'],
            currency: $validated['currency'] ?? null,
        );

        return response()->json([
            'success' => true,
            'report'  => $report,
        ]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'     => ['required', 'date'],
            'to'       => ['required', 'date', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $report = $this->cashFlow->generate(
            from:     $validated['from'],
            to:       $validated['to'],
            currency: $validated['currency'] ?? null,
        );

        return response()->json([
            'success' => true,
            'report'  => $report,
        ]);
    }
}
