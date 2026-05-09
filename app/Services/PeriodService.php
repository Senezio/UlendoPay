<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Reporting\TrialBalanceService;
use App\Services\Reporting\BalanceSheetService;
use App\Services\Reporting\ProfitLossService;
use App\Services\Reporting\CashFlowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Period Service
 *
 * Manages accounting period lifecycle: open → closed → locked.
 *
 * Open:
 *   - Journal entries may be posted with dates within this period.
 *   - Only one period may be open at a time.
 *
 * Close:
 *   - No new entries may be posted with dates in this period.
 *   - Financial statement snapshots are captured and stored.
 *   - Adjusting entries may still be posted in the NEXT open period.
 *   - Status can be re-opened if a close was premature (before lock).
 *
 * Lock:
 *   - Period is permanently immutable.
 *   - Snapshots are captured again (after any close-period adjustments).
 *   - Cannot be unlocked. Corrections go into a new period.
 *   - LedgerService::post() enforces this at entry time.
 */
class PeriodService
{
    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly BalanceSheetService $balanceSheet,
        private readonly ProfitLossService   $profitLoss,
        private readonly CashFlowService     $cashFlow,
    ) {}

    // ── Open ─────────────────────────────────────────────────────────────────

    /**
     * Open a new accounting period.
     *
     * @param  string  $startsOn  ISO date string (e.g. "2026-05-01")
     * @param  string  $endsOn    ISO date string (e.g. "2026-05-31")
     * @param  string  $name      Human-readable label (e.g. "May 2026")
     * @param  User    $openedBy  Admin user performing the action
     */
    public function open(
        string $startsOn,
        string $endsOn,
        string $name,
        User   $openedBy
    ): AccountingPeriod {
        $starts = Carbon::parse($startsOn)->startOfDay();
        $ends   = Carbon::parse($endsOn)->endOfDay();

        if ($starts->greaterThanOrEqualTo($ends)) {
            throw new \RuntimeException("Period start must be before end.");
        }

        return DB::transaction(function () use ($starts, $ends, $name, $openedBy) {
            // Only one open period allowed at a time
            $existing = AccountingPeriod::open()->first();
            if ($existing) {
                throw new \RuntimeException(
                    "Period '{$existing->name}' is already open. Close it before opening a new one."
                );
            }

            // Check for date overlap with any existing period
            $overlap = AccountingPeriod::where(function ($q) use ($starts, $ends) {
                $q->whereBetween('starts_on', [$starts->toDateString(), $ends->toDateString()])
                  ->orWhereBetween('ends_on', [$starts->toDateString(), $ends->toDateString()])
                  ->orWhere(function ($q2) use ($starts, $ends) {
                      $q2->where('starts_on', '<=', $starts->toDateString())
                         ->where('ends_on', '>=', $ends->toDateString());
                  });
            })->first();

            if ($overlap) {
                throw new \RuntimeException(
                    "Date range overlaps with existing period '{$overlap->name}'."
                );
            }

            $period = AccountingPeriod::create([
                'name'      => $name,
                'starts_on' => $starts->toDateString(),
                'ends_on'   => $ends->toDateString(),
                'status'    => 'open',
                'opened_by' => $openedBy->id,
                'opened_at' => now(),
            ]);

            $this->audit($openedBy, 'period.opened', $period, [
                'name'       => $name,
                'starts_on'  => $starts->toDateString(),
                'ends_on'    => $ends->toDateString(),
            ]);

            Log::info('[PeriodService] Period opened', [
                'period_id' => $period->id,
                'name'      => $name,
                'opened_by' => $openedBy->id,
            ]);

            return $period;
        });
    }

    // ── Close ────────────────────────────────────────────────────────────────

    /**
     * Close an open period.
     * Captures financial statement snapshots at this point in time.
     * Period can be re-opened before locking if needed.
     */
    public function close(AccountingPeriod $period, User $closedBy): AccountingPeriod
    {
        if (! $period->isOpen()) {
            throw new \RuntimeException(
                "Period '{$period->name}' is not open (status: {$period->status})."
            );
        }

        return DB::transaction(function () use ($period, $closedBy) {
            $snapshots = $this->captureSnapshots($period);

            $bs = $snapshots['balance_sheet'];
            $pl = $snapshots['profit_loss'];
            $cf = $snapshots['cash_flow'];

            $period->update([
                'status'                 => 'closed',
                'closed_by'              => $closedBy->id,
                'closed_at'              => now(),
                'trial_balance_snapshot' => $snapshots['trial_balance'],
                'balance_sheet_snapshot' => $bs,
                'profit_loss_snapshot'   => $pl,
                'cash_flow_snapshot'     => $cf,
                // Summary figures for quick display
                'total_assets'           => null,
                'total_liabilities'      => null,
                'total_equity'           => null,
                'net_profit'             => $pl['totals']['net_profit'] ?? null,
                'net_cash_change'        => $cf['totals']['net_change'] ?? null,
            ]);

            $this->audit($closedBy, 'period.closed', $period, [
                'ledger_balanced'   => $bs['ledger_balanced'] ?? false,
                'ledger_sum'        => $bs['ledger_sum'] ?? null,
                'net_profit'        => $period->net_profit,
                'net_cash_change'   => $period->net_cash_change,
            ]);

            Log::info('[PeriodService] Period closed', [
                'period_id' => $period->id,
                'name'      => $period->name,
                'closed_by' => $closedBy->id,
                'net_profit' => $period->net_profit,
            ]);

            return $period->fresh();
        });
    }

    // ── Re-open ──────────────────────────────────────────────────────────────

    /**
     * Re-open a closed (but not locked) period.
     * Used when close was premature and adjustments are still needed.
     */
    public function reopen(AccountingPeriod $period, User $reopenedBy): AccountingPeriod
    {
        if ($period->isLocked()) {
            throw new \RuntimeException(
                "Period '{$period->name}' is locked and cannot be re-opened. "
                . "Post adjustments in the next open period."
            );
        }

        if ($period->isOpen()) {
            throw new \RuntimeException("Period '{$period->name}' is already open.");
        }

        // Only one open period allowed at a time
        $existing = AccountingPeriod::open()->first();
        if ($existing) {
            throw new \RuntimeException(
                "Period '{$existing->name}' is already open. Close it before re-opening another."
            );
        }

        return DB::transaction(function () use ($period, $reopenedBy) {
            $period->update([
                'status'    => 'open',
                'closed_by' => null,
                'closed_at' => null,
                // Clear close-time snapshots — they will be re-captured on next close
                'trial_balance_snapshot' => null,
                'balance_sheet_snapshot' => null,
                'profit_loss_snapshot'   => null,
                'cash_flow_snapshot'     => null,
                'total_assets'           => null,
                'total_liabilities'      => null,
                'total_equity'           => null,
                'net_profit'             => null,
                'net_cash_change'        => null,
            ]);

            $this->audit($reopenedBy, 'period.reopened', $period, []);

            Log::warning('[PeriodService] Period re-opened', [
                'period_id'   => $period->id,
                'name'        => $period->name,
                'reopened_by' => $reopenedBy->id,
            ]);

            return $period->fresh();
        });
    }

    // ── Lock ─────────────────────────────────────────────────────────────────

    /**
     * Lock a closed period permanently.
     *
     * This is IRREVERSIBLE. Locked periods cannot be unlocked.
     * A final set of snapshots is captured at lock time.
     * LedgerService::post() will reject any entry dated within a locked period.
     */
    public function lock(AccountingPeriod $period, User $lockedBy, ?string $notes = null): AccountingPeriod
    {
        if (! $period->isClosed()) {
            throw new \RuntimeException(
                "Only closed periods can be locked. "
                . "Current status: {$period->status}."
            );
        }

        return DB::transaction(function () use ($period, $lockedBy, $notes) {
            // Capture final snapshots at lock time
            $snapshots = $this->captureSnapshots($period);

            $bs = $snapshots['balance_sheet'];
            $pl = $snapshots['profit_loss'];
            $cf = $snapshots['cash_flow'];

            $period->update([
                'status'               => 'locked',
                'locked_by'            => $lockedBy->id,
                'locked_at'            => now(),
                'notes'                => $notes,
                // Final locked snapshots — these are the definitive audit record
                'locked_trial_balance' => $snapshots['trial_balance'],
                'locked_balance_sheet' => $bs,
                'locked_profit_loss'   => $pl,
                'locked_cash_flow'     => $cf,
                // Update summary figures from final locked snapshots
                'total_assets'         => null,
                'total_liabilities'    => null,
                'total_equity'         => null,
                'net_profit'           => $pl['totals']['net_profit'] ?? $period->net_profit,
                'net_cash_change'      => $cf['totals']['net_change'] ?? $period->net_cash_change,
            ]);

            $this->audit($lockedBy, 'period.locked', $period, [
                'notes'             => $notes,
                'ledger_balanced'   => $bs['ledger_balanced'] ?? false,
                'ledger_sum'        => $bs['ledger_sum'] ?? null,
                'net_profit'        => $period->net_profit,
            ]);

            Log::info('[PeriodService] Period locked', [
                'period_id' => $period->id,
                'name'      => $period->name,
                'locked_by' => $lockedBy->id,
            ]);

            return $period->fresh();
        });
    }

    // ── Guard ────────────────────────────────────────────────────────────────

    /**
     * Called by LedgerService::post() before posting any journal entry.
     * Throws if the entry date falls within a locked period.
     */
    public function assertNotLocked(Carbon $entryDate): void
    {
        $period = AccountingPeriod::forDate($entryDate);

        if ($period && $period->isLocked()) {
            throw new \RuntimeException(
                "Cannot post journal entries into locked period '{$period->name}' "
                . "({$period->starts_on->toDateString()} to {$period->ends_on->toDateString()}). "
                . "Post adjustments in the next open period."
            );
        }
    }

    // ── Snapshot helper ──────────────────────────────────────────────────────

    /**
     * Capture all four financial statements for the given period.
     * Returns array of report arrays ready to be stored as JSON.
     */
    private function captureSnapshots(AccountingPeriod $period): array
    {
        $from = $period->starts_on->toDateString();
        $to   = $period->ends_on->toDateString();
        $asOf = $period->ends_on->toDateString();

        return [
            'trial_balance' => $this->trialBalance->generate(null, $asOf),
            'balance_sheet' => $this->balanceSheet->generate(null, $asOf),
            'profit_loss'   => $this->profitLoss->generate($from, $to),
            'cash_flow'     => $this->cashFlow->generate($from, $to),
        ];
    }

    // ── Audit helper ─────────────────────────────────────────────────────────

    private function audit(User $user, string $action, AccountingPeriod $period, array $data): void
    {
        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => $action,
            'entity_type' => 'AccountingPeriod',
            'entity_id'   => $period->id,
            'new_values'  => array_merge(['period_name' => $period->name], $data),
        ]);
    }
}
