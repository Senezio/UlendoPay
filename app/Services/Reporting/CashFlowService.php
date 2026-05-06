<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Cash Flow Statement (Indirect Method)
 *
 * Shows how cash moved through the system during a period.
 * Categorised into three activities:
 *
 *   Operating:  day-to-day transfer, fee, escrow operations
 *   Financing:  guarantee fund contributions and payouts
 *   Investing:  (none currently — placeholder for future use)
 *
 * Method used here: DIRECT (simpler given our ledger structure).
 * We sum the actual cash movements (debit/credit to user_wallet accounts)
 * per group type, giving a clear picture of money in vs money out.
 *
 * "Cash" in this system = movements on user_wallet and escrow accounts
 * (accounts that represent real money positions, not fee buckets or equity).
 *
 * Source: journal_entries joined to journal_entry_groups, filtered by posted_at.
 */
class CashFlowService
{
    private const CASH_ACCOUNT_TYPES = ['user_wallet', 'escrow'];

    /**
     * Generate cash flow statement for a given period.
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

        $movements = $this->fetchCashMovements($fromDate, $toDate, $currency);

        $activities = [
            'operating'  => ['label' => 'Operating Activities',  'items' => [], 'total' => '0.000000'],
            'financing'  => ['label' => 'Financing Activities',  'items' => [], 'total' => '0.000000'],
            'investing'  => ['label' => 'Investing Activities',  'items' => [], 'total' => '0.000000'],
        ];

        foreach ($movements as $row) {
            $activity = AccountClassifier::cashFlowActivity($row['group_type']);

            $netFlow = bcsub(
                (string) $row['credit_total'],
                (string) $row['debit_total'],
                6
            );

            $activities[$activity]['items'][] = [
                'group_type'     => $row['group_type'],
                'label'          => $this->labelForGroupType($row['group_type']),
                'currency_code'  => $row['currency_code'],
                'transaction_count' => (int) $row['transaction_count'],
                'total_debits'   => (string) $row['debit_total'],
                'total_credits'  => (string) $row['credit_total'],
                'net_flow'       => $netFlow,
            ];

            $activities[$activity]['total'] = bcadd(
                $activities[$activity]['total'],
                $netFlow,
                6
            );
        }

        $netOperating  = $activities['operating']['total'];
        $netFinancing  = $activities['financing']['total'];
        $netInvesting  = $activities['investing']['total'];

        $netChange = bcadd(
            bcadd($netOperating, $netFinancing, 6),
            $netInvesting,
            6
        );

        $openingCash = $this->getCashBalance($currency, $fromDate->copy()->subSecond());
        $closingCash = $this->getCashBalance($currency, $toDate);

        return [
            'generated_at'   => Carbon::now()->toIso8601String(),
            'period_from'    => $fromDate->toDateString(),
            'period_to'      => $toDate->toDateString(),
            'currency'       => $currency,
            'activities'     => $activities,
            'totals'         => [
                'net_operating'  => $netOperating,
                'net_financing'  => $netFinancing,
                'net_investing'  => $netInvesting,
                'net_change'     => $netChange,
                'opening_cash'   => $openingCash,
                'closing_cash'   => $closingCash,
            ],
        ];
    }

    /**

    /**
     * Fetch debit/credit totals on cash accounts, grouped by journal_entry_group.type.
     */
    private function fetchCashMovements(Carbon $from, Carbon $to, ?string $currency): array
    {
        $query = DB::table('journal_entries as je')
            ->join('journal_entry_groups as jeg', function ($join) {
                $join->on('jeg.id', '=', 'je.group_id')
                     ->where('jeg.status', 'posted');
            })
            ->join('accounts as a', 'a.id', '=', 'je.account_id')
            ->whereBetween('je.posted_at', [$from, $to])
            ->whereIn('a.type', self::CASH_ACCOUNT_TYPES)
            ->select([
                'jeg.type as group_type',
                'je.currency_code',
                DB::raw('COUNT(DISTINCT jeg.id) as transaction_count'),
                DB::raw("SUM(CASE WHEN je.entry_type = 'debit'  THEN je.amount ELSE 0 END) as debit_total"),
                DB::raw("SUM(CASE WHEN je.entry_type = 'credit' THEN je.amount ELSE 0 END) as credit_total"),
            ])
            ->groupBy('jeg.type', 'je.currency_code')
            ->orderBy('jeg.type');

        if ($currency) {
            $query->where('je.currency_code', $currency);
        }

        return $query->get()->map(fn ($r) => (array) $r)->toArray();
    }

    /**
     * @param  string|null  
     * @param  Carbon       
     * @return string
     */
    private function getCashBalance(?string $currency, Carbon $asOf): string
    {
        $query = DB::table('journal_entries as je')
            ->join('journal_entry_groups as jeg', function ($join) {
                $join->on('jeg.id', '=', 'je.group_id')
                     ->where('jeg.status', 'posted');
            })
            ->join('accounts as a', 'a.id', '=', 'je.account_id')
            ->where('je.posted_at', '<=', $asOf)
            ->whereIn('a.type', self::CASH_ACCOUNT_TYPES)
            ->selectRaw("
                SUM(CASE
                    WHEN a.normal_balance = 'debit' AND je.entry_type = 'debit'   THEN  je.amount
                    WHEN a.normal_balance = 'debit' AND je.entry_type = 'credit'  THEN -je.amount
                    WHEN a.normal_balance = 'credit' AND je.entry_type = 'credit' THEN  je.amount
                    WHEN a.normal_balance = 'credit' AND je.entry_type = 'debit'  THEN -je.amount
                    ELSE 0
                END) as cash_balance
            ");

        if ($currency) {
            $query->where('je.currency_code', $currency);
        }

        $result = $query->value('cash_balance');
        return $result ? (string) number_format((float) $result, 6, '.', '') : '0.000000';
    }

    /**
     * Human-readable labels for journal_entry_group types.
     */
    private function labelForGroupType(string $type): string
    {
        return match($type) {
            'transfer_initiation'     => 'Transfer Initiations',
            'transfer_completion'     => 'Transfer Completions',
            'transfer_reversal'       => 'Transfer Reversals',
            'transfer_credit'         => 'Transfer Credits',
            'transfer_debit'          => 'Transfer Debits',
            'transfer_escrow_release' => 'Transfer Escrow Releases',
            'escrow_release'          => 'Escrow Releases',
            'fee_collection'          => 'Fee Collections',
            'guarantee_contribution'  => 'Guarantee Contributions',
            'guarantee_payout'        => 'Guarantee Payouts',
            'adjustment'              => 'Adjustments',
            default                   => ucwords(str_replace('_', ' ', $type)),
        };
    }
}
