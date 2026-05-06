<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Balance Sheet (Statement of Financial Position)
 *
 * Fundamental equation: Assets = Liabilities + Equity
 *
 * Account type mapping (from AccountClassifier):
 *   Assets:      user_wallet, escrow, guarantee
 *   Liabilities: partner
 *   Equity:      system
 *
 * Source: account_balances for current; journal_entries for historical.
 *
 * Note on escrow:
 *   Escrow accounts are treated as assets here (we hold the float).
 *   In strict GAAP you would show an offsetting liability "funds held for customers."
 *   This can be added later if needed — for now, escrow balance = asset.
 */
class BalanceSheetService
{
    public function __construct(
        private readonly TrialBalanceService $trialBalance
    ) {}

    /**
     * Generate balance sheet.
     *
     * @param  string|null  $currency  Filter by currency (null = all currencies)
     * @param  string|null  $asOf      Point-in-time date (null = current)
     * @return array
     */
    public function generate(?string $currency = null, ?string $asOf = null): array
    {
        $tb = $this->trialBalance->generate($currency, $asOf);

        $sections = [
            'asset'     => ['label' => 'Assets',       'accounts' => [], 'total' => '0.000000'],
            'liability' => ['label' => 'Liabilities',  'accounts' => [], 'total' => '0.000000'],
            'equity'    => ['label' => 'Equity',       'accounts' => [], 'total' => '0.000000'],
        ];

        foreach ($tb['accounts'] as $account) {
            $category = $account['category'];

            if (! isset($sections[$category])) {
                continue;
            }

            $balance = (string) ($account['balance'] ?? '0');

            $sections[$category]['accounts'][] = [
                'id'             => $account['id'],
                'code'           => $account['code'],
                'type'           => $account['type'],
                'currency_code'  => $account['currency_code'],
                'corridor'       => $account['corridor'],
                'normal_balance' => $account['normal_balance'],
                'balance'        => $balance,
            ];

            $sections[$category]['total'] = bcadd(
                $sections[$category]['total'],
                $balance,
                6
            );
        }

        $totalAssets      = $sections['asset']['total'];
        $totalLiabilities = $sections['liability']['total'];
        $totalEquity      = $sections['equity']['total'];
        $liabilitiesPlusEquity = bcadd($totalLiabilities, $totalEquity, 6);

        return [
            'generated_at'           => Carbon::now()->toIso8601String(),
            'as_of'                  => $asOf ?? Carbon::now()->toDateString(),
            'currency'               => $currency,
            'sections'               => $sections,
            'totals'                 => [
                'total_assets'               => $totalAssets,
                'total_liabilities'          => $totalLiabilities,
                'total_equity'               => $totalEquity,
                'total_liabilities_equity'   => $liabilitiesPlusEquity,
                'equation_balanced'          => bccomp($totalAssets, $liabilitiesPlusEquity, 6) === 0,
            ],
        ];
    }
}
