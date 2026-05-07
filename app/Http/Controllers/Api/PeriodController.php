<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Services\PeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accounting Period Management
 *
 * All routes require admin middleware.
 *
 * Workflow:
 *   POST   /api/v1/periods              → open a new period
 *   GET    /api/v1/periods              → list all periods
 *   GET    /api/v1/periods/{id}         → get period detail with snapshots
 *   POST   /api/v1/periods/{id}/close   → close period (captures snapshots)
 *   POST   /api/v1/periods/{id}/reopen  → re-open a closed (not locked) period
 *   POST   /api/v1/periods/{id}/lock    → lock period permanently (irreversible)
 */
class PeriodController extends Controller
{
    public function __construct(private readonly PeriodService $periods) {}

    // ── GET /api/v1/periods ──────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $periods = AccountingPeriod::with(['openedBy', 'closedBy', 'lockedBy'])
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn ($p) => $this->formatPeriod($p, false));

        return response()->json(['periods' => $periods]);
    }

    // ── GET /api/v1/periods/{id} ─────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $period = AccountingPeriod::with(['openedBy', 'closedBy', 'lockedBy'])
            ->findOrFail($id);

        return response()->json(['period' => $this->formatPeriod($period, true)]);
    }

    // ── POST /api/v1/periods ─────────────────────────────────────────────────

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'starts_on'  => ['required', 'date'],
            'ends_on'    => ['required', 'date', 'after:starts_on'],
        ]);

        $period = $this->periods->open(
            startsOn:  $data['starts_on'],
            endsOn:    $data['ends_on'],
            name:      $data['name'],
            openedBy:  $request->user(),
        );

        return response()->json([
            'message' => "Period '{$period->name}' opened successfully.",
            'period'  => $this->formatPeriod($period, false),
        ], 201);
    }

    // ── POST /api/v1/periods/{id}/close ─────────────────────────────────────

    public function close(Request $request, int $id): JsonResponse
    {
        $period = AccountingPeriod::findOrFail($id);

        $period = $this->periods->close($period, $request->user());

        return response()->json([
            'message' => "Period '{$period->name}' closed. Financial statements captured.",
            'period'  => $this->formatPeriod($period, false),
            'summary' => [
                'total_assets'      => $period->total_assets,
                'total_liabilities' => $period->total_liabilities,
                'total_equity'      => $period->total_equity,
                'net_profit'        => $period->net_profit,
                'net_cash_change'   => $period->net_cash_change,
            ],
        ]);
    }

    // ── POST /api/v1/periods/{id}/reopen ────────────────────────────────────

    public function reopen(Request $request, int $id): JsonResponse
    {
        $period = AccountingPeriod::findOrFail($id);

        $period = $this->periods->reopen($period, $request->user());

        return response()->json([
            'message' => "Period '{$period->name}' re-opened.",
            'period'  => $this->formatPeriod($period, false),
        ]);
    }

    // ── POST /api/v1/periods/{id}/lock ──────────────────────────────────────

    public function lock(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'notes'   => ['nullable', 'string', 'max:500'],
            'confirm' => ['required', 'accepted'],  // must send confirm=true
        ]);

        $period = AccountingPeriod::findOrFail($id);

        $period = $this->periods->lock(
            period:   $period,
            lockedBy: $request->user(),
            notes:    $data['notes'] ?? null,
        );

        return response()->json([
            'message' => "Period '{$period->name}' is now permanently locked.",
            'period'  => $this->formatPeriod($period, false),
        ]);
    }

    // ── GET /api/v1/periods/{id}/snapshots ───────────────────────────────────

    public function snapshots(int $id): JsonResponse
    {
        $period = AccountingPeriod::findOrFail($id);

        if ($period->isOpen()) {
            return response()->json([
                'message' => 'No snapshots available — period is still open.',
            ], 422);
        }

        // Return locked snapshots if available, otherwise close-time snapshots
        $useLocked = $period->isLocked() && $period->locked_balance_sheet;

        return response()->json([
            'period_id'      => $period->id,
            'period_name'    => $period->name,
            'snapshot_type'  => $useLocked ? 'locked' : 'closed',
            'trial_balance'  => $useLocked ? $period->locked_trial_balance : $period->trial_balance_snapshot,
            'balance_sheet'  => $useLocked ? $period->locked_balance_sheet : $period->balance_sheet_snapshot,
            'profit_loss'    => $useLocked ? $period->locked_profit_loss   : $period->profit_loss_snapshot,
            'cash_flow'      => $useLocked ? $period->locked_cash_flow     : $period->cash_flow_snapshot,
        ]);
    }

    // ── Formatter ────────────────────────────────────────────────────────────

    private function formatPeriod(AccountingPeriod $p, bool $includeSnapshots): array
    {
        $data = [
            'id'                => $p->id,
            'name'              => $p->name,
            'starts_on'         => $p->starts_on?->toDateString(),
            'ends_on'           => $p->ends_on?->toDateString(),
            'status'            => $p->status,
            'opened_by'         => $p->openedBy?->name,
            'closed_by'         => $p->closedBy?->name,
            'locked_by'         => $p->lockedBy?->name,
            'opened_at'         => $p->opened_at?->toIso8601String(),
            'closed_at'         => $p->closed_at?->toIso8601String(),
            'locked_at'         => $p->locked_at?->toIso8601String(),
            'notes'             => $p->notes,
            'total_assets'      => $p->total_assets,
            'total_liabilities' => $p->total_liabilities,
            'total_equity'      => $p->total_equity,
            'net_profit'        => $p->net_profit,
            'net_cash_change'   => $p->net_cash_change,
        ];

        if ($includeSnapshots && ! $p->isOpen()) {
            $useLocked = $p->isLocked() && $p->locked_balance_sheet;
            $data['snapshots'] = [
                'type'          => $useLocked ? 'locked' : 'closed',
                'trial_balance' => $useLocked ? $p->locked_trial_balance : $p->trial_balance_snapshot,
                'balance_sheet' => $useLocked ? $p->locked_balance_sheet : $p->balance_sheet_snapshot,
                'profit_loss'   => $useLocked ? $p->locked_profit_loss   : $p->profit_loss_snapshot,
                'cash_flow'     => $useLocked ? $p->locked_cash_flow     : $p->cash_flow_snapshot,
            ];
        }

        return $data;
    }
}
