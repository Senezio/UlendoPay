<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/** Generates the trial balance report. */
class TrialBalanceService
{
    /**
     * Generate trial balance.
     *
     * @param  string|null  $currency  Filter by currency (null = all currencies)
     * @param  string|null  $asOf      ISO date string — balances as of this date
     *                                 (null = current balances from account_balances)
     * @return array{
     *   generated_at: string,
     *   as_of: string|null,
     *   currency: string|null,
     *   accounts: array,
     *   totals: array{total_debits: string, total_credits: string, is_balanced: bool}
     * }
     */
    public function generate(?string $currency = null, ?string $asOf = null): array
    {
        $accounts = $asOf
            ? $this->computeFromJournal($currency, $asOf)
            : $this->readFromCache($currency);

        $totalDebits  = '0.000000';
        $totalCredits = '0.000000';

        foreach ($accounts as &$account) {
            $balance  = (string) $account['balance'];
            $category = AccountClassifier::categoryForAccount($account['type'], $account['code']);

            $account['category']       = $category;
            $account['category_label'] = AccountClassifier::label($category);

            if ($account['normal_balance'] === 'debit') {
                $account['debit_balance']  = $balance;
                $account['credit_balance'] = null;
                $totalDebits = bcadd($totalDebits, $balance, 6);
            } else {
                $account['debit_balance']  = null;
                $account['credit_balance'] = $balance;
                $totalCredits = bcadd($totalCredits, $balance, 6);
            }
        }
        unset($account);

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'as_of'        => $asOf,
            'currency'     => $currency,
            'accounts'     => $accounts,
            'totals'       => [
                'total_debits'  => $totalDebits,
                'total_credits' => $totalCredits,
                'is_balanced'   => bccomp($totalDebits, $totalCredits, 6) === 0,
            ],
        ];
    }

    /**
     * @param  string|null  
     * @return array
     */
    private function readFromCache(?string $currency): array
    {
        $query = DB::table('accounts as a')
            ->join('account_balances as ab', 'ab.account_id', '=', 'a.id')
            ->select([
                'a.id',
                'a.code',
                'a.type',
                'a.normal_balance',
                'a.currency_code',
                'a.corridor',
                'ab.balance',
            ])
            ->where('a.is_active', true)
            ->orderBy('a.type')
            ->orderBy('a.code');

        if ($currency) {
            $query->where('a.currency_code', $currency);
        }

        return $query->get()->map(fn ($r) => (array) $r)->toArray();
    }

    /**
     * Slow path: reconstruct balances from journal_entries up to $asOf date.
     * Used for point-in-time historical trial balances.
     */
    private function computeFromJournal(?string $currency, string $asOf): array
    {
        $asOfDate = Carbon::parse($asOf)->endOfDay();

        $query = DB::table('accounts as a')
            ->leftJoin('journal_entries as je', function ($join) use ($asOfDate) {
                $join->on('je.account_id', '=', 'a.id')
                     ->where('je.posted_at', '<=', $asOfDate);
            })
            ->leftJoin('journal_entry_groups as jeg', function ($join) {
                $join->on('jeg.id', '=', 'je.group_id')
                     ->where('jeg.status', 'posted');
            })
            ->select([
                'a.id',
                'a.code',
                'a.type',
                'a.normal_balance',
                'a.currency_code',
                'a.corridor',
                DB::raw("
                    SUM(CASE
                        WHEN a.normal_balance = 'debit'
                        THEN (COALESCE(CASE WHEN je.entry_type = 'debit'  THEN je.amount ELSE 0 END, 0)
                             - COALESCE(CASE WHEN je.entry_type = 'credit' THEN je.amount ELSE 0 END, 0))
                        ELSE (COALESCE(CASE WHEN je.entry_type = 'credit' THEN je.amount ELSE 0 END, 0)
                             - COALESCE(CASE WHEN je.entry_type = 'debit'  THEN je.amount ELSE 0 END, 0))
                    END) as balance
                "),
            ])
            ->where('a.is_active', true)
            ->groupBy('a.id', 'a.code', 'a.type', 'a.normal_balance', 'a.currency_code', 'a.corridor')
            ->orderBy('a.type')
            ->orderBy('a.code');

        if ($currency) {
            $query->where('a.currency_code', $currency);
        }

        return $query->get()->map(fn ($r) => (array) $r)->toArray();
    }
}
