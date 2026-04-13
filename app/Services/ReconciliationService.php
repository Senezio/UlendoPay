<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\ReconciliationSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconciliationService
{
    /**
     * Run daily reconciliation for all active accounts.
     * Computes balance from journal entries and compares
     * against previous snapshot to detect any variance.
     */
    public function runDaily(): array
    {
        $date     = now()->toDateString();
        $accounts = Account::where('is_active', true)->get();

        $results = [
            'date'      => $date,
            'total'     => $accounts->count(),
            'matched'   => 0,
            'mismatch'  => 0,
            'errors'    => 0,
        ];

        foreach ($accounts as $account) {
            try {
                $this->reconcileAccount($account, $date);
                $results['matched']++;
            } catch (\Throwable $e) {
                $results['errors']++;
                Log::error('[Reconciliation] Failed for account', [
                    'account_id' => $account->id,
                    'code'       => $account->code,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // Re-count mismatches from DB
        $results['mismatch'] = ReconciliationSnapshot::where('snapshot_date', $date)
            ->where('status', 'mismatch')
            ->count();

        $results['matched'] = $results['total'] - $results['mismatch'] - $results['errors'];

        Log::info('[Reconciliation] Daily run complete', $results);

        return $results;
    }

    /**
     * Reconcile a single account for a given date.
     *
     * Computed balance = sum of all journal entries up to end of day.
     * Expected balance = previous snapshot computed balance.
     * Variance        = computed - expected.
     */
    private function reconcileAccount(Account $account, string $date): void
    {
        // Sum all debits and credits for this account up to end of snapshot date
        $totals = JournalEntry::where('account_id', $account->id)
            ->whereDate('posted_at', '<=', $date)
            ->selectRaw('entry_type, SUM(amount) as total')
            ->groupBy('entry_type')
            ->pluck('total', 'entry_type');

        $totalDebits  = (float) ($totals['debit']  ?? 0);
        $totalCredits = (float) ($totals['credit'] ?? 0);

        // Computed balance depends on normal balance convention
        $computedBalance = $account->normal_balance === 'debit'
            ? $totalDebits - $totalCredits
            : $totalCredits - $totalDebits;

        // Expected balance = previous snapshot, or 0 if first ever
        $previous = ReconciliationSnapshot::where('account_id', $account->id)
            ->where('snapshot_date', '<', $date)
            ->orderByDesc('snapshot_date')
            ->first();

        $expectedBalance = $previous ? (float) $previous->computed_balance : 0.0;

        $variance = round($computedBalance - $expectedBalance, 6);
        $status   = abs($variance) < 0.000001 ? 'matched' : 'mismatch';

        if ($status === 'mismatch') {
            Log::warning('[Reconciliation] Variance detected', [
                'account'          => $account->code,
                'computed_balance' => $computedBalance,
                'expected_balance' => $expectedBalance,
                'variance'         => $variance,
                'date'             => $date,
            ]);
        }

        ReconciliationSnapshot::updateOrCreate(
            [
                'account_id'    => $account->id,
                'snapshot_date' => $date,
            ],
            [
                'computed_balance' => $computedBalance,
                'expected_balance' => $expectedBalance,
                'variance'         => $variance,
                'status'           => $status,
                'notes'            => $status === 'mismatch'
                    ? "Variance of {$variance} detected on {$date}"
                    : null,
            ]
        );
    }
}