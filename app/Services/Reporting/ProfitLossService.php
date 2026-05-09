<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Profit & Loss Statement (Income Statement)
 *
 * Formula: Net Profit = Revenue - Expenses
 *
 * Account type mapping:
 *   Revenue/Income: fee accounts (platform fees collected)
 *   Expenses:       none explicitly defined yet in current schema.
 *                   If expense accounts are added (type = 'expense'),
 *                   they will automatically appear here.
 *
 * P&L REQUIRES a date range — it reports activity over a period,
 * not a snapshot. Use journal_entries.posted_at for period filtering.
 *
 * Important: P&L reads journal_entries directly (not account_balances cache)
 * because account_balances is a running cumulative total with no period reset.
 * We need the movement within [from, to] only.
 */
class ProfitLossService
{
    /**
     * Generate P&L for a given period.
     *
     * @param  string       $from      ISO date string (start of period, inclusive)
     * @param  string       $to        ISO date string (end of period, inclusive)
     * @param  string|null  $currency  Filter by currency (null = all currencies)
     * @return array
     */
    public function generate(string $from, string $to, ?string $currency = null): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate   = Carbon::parse($to)->endOfDay();

        $rows = $this->fetchAccountMovements($fromDate, $toDate, $currency);

        $sections = [
            'income'  => ['label' => 'Revenue',  'accounts' => [], 'total' => '0.000000'],
            'expense' => ['label' => 'Expenses', 'accounts' => [], 'total' => '0.000000'],
        ];

        foreach ($rows as $row) {
            $category = AccountClassifier::categoryForAccount($row['type'], $row['code']);

            if (! isset($sections[$category])) {
                continue;
            }

            if ($row['normal_balance'] === 'credit') {
                $net = bcsub((string) $row['credit_total'], (string) $row['debit_total'], 6);
            } else {
                $net = bcsub((string) $row['debit_total'], (string) $row['credit_total'], 6);
            }

            $sections[$category]['accounts'][] = [
                'id'             => $row['id'],
                'code'           => $row['code'],
                'type'           => $row['type'],
                'currency_code'  => $row['currency_code'],
                'corridor'       => $row['corridor'],
                'normal_balance' => $row['normal_balance'],
                'debit_total'    => (string) $row['debit_total'],
                'credit_total'   => (string) $row['credit_total'],
                'net_movement'   => $net,
            ];

            $sections[$category]['total'] = bcadd(
                $sections[$category]['total'],
                $net,
                6
            );
        }

        $totalRevenue  = $sections['income']['total'];
        $totalExpenses = $sections['expense']['total'];
        $netProfit     = bcsub($totalRevenue, $totalExpenses, 6);

        return [
            'generated_at'   => Carbon::now()->toIso8601String(),
            'period_from'    => $fromDate->toDateString(),
            'period_to'      => $toDate->toDateString(),
            'currency'       => $currency,
            'sections'       => $sections,
            'totals'         => [
                'total_revenue'  => $totalRevenue,
                'total_expenses' => $totalExpenses,
                'net_profit'     => $netProfit,
                'is_profitable'  => bccomp($netProfit, '0', 6) >= 0,
            ],
        ];
    }

    /**

    /**
     * Fetch debit/credit totals per P&L account for the given period.
     * Only reads posted journal entries (status = 'posted' on the group).
     */
    private function fetchAccountMovements(
        Carbon $from,
        Carbon $to,
        ?string $currency
    ): array {
        $query = DB::table('accounts as a')
            ->join('journal_entries as je', 'je.account_id', '=', 'a.id')
            ->join('journal_entry_groups as jeg', function ($join) {
                $join->on('jeg.id', '=', 'je.group_id')
                     ->where('jeg.status', 'posted');
            })
            ->whereBetween('je.posted_at', [$from, $to])
            ->select([
                'a.id',
                'a.code',
                'a.type',
                'a.normal_balance',
                'a.currency_code',
                'a.corridor',
                DB::raw("SUM(CASE WHEN je.entry_type = 'debit'  THEN je.amount ELSE 0 END) as debit_total"),
                DB::raw("SUM(CASE WHEN je.entry_type = 'credit' THEN je.amount ELSE 0 END) as credit_total"),
            ])
            ->whereIn("a.type", ["fee", "expense"])
            ->groupBy('a.id', 'a.code', 'a.type', 'a.normal_balance', 'a.currency_code', 'a.corridor')
            ->orderBy('a.type')
            ->orderBy('a.code');

        if ($currency) {
            $query->where('a.currency_code', $currency);
        }

        return $query->get()->map(fn ($r) => (array) $r)->toArray();
    }
}
