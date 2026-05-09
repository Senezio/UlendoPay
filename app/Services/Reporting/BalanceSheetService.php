<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;

/**
 * Balance Sheet (Statement of Financial Position)
 *
 * Reports per currency. Mixing currencies into a single total is
 * meaningless — MWK and ZMW cannot be added together.
 *
 * All accounts are normal=credit in this ledger.
 * Positive balance = net credit (liability owed or income earned).
 * Negative balance = net debit (e.g. POOL after disbursement).
 *
 * Pre-closing treatment:
 *   Fee (income) accounts are reclassified to equity as
 *   unappropriated retained earnings until period close.
 *
 * Equation per currency: Liabilities + Equity = 0
 * (Assets = 0 in this liability-centric ledger — no asset accounts yet.)
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

        // Group accounts by currency, then by section
        $byCurrency = [];

        foreach ($tb['accounts'] as $account) {
            $category = $account['category'];

            // Fee (income) accounts reclassified to equity pre-closing
            if ($category === 'income') {
                $category = 'equity';
            }

            if (! in_array($category, ['asset', 'liability', 'equity'])) {
                continue;
            }

            $ccy     = $account['currency_code'];
            $balance = (string) ($account['balance'] ?? '0');

            if (! isset($byCurrency[$ccy])) {
                $byCurrency[$ccy] = [
                    'currency' => $ccy,
                    'sections' => [
                        'asset'     => ['label' => 'Assets',      'accounts' => [], 'total' => '0.000000'],
                        'liability' => ['label' => 'Liabilities', 'accounts' => [], 'total' => '0.000000'],
                        'equity'    => ['label' => 'Equity',      'accounts' => [], 'total' => '0.000000'],
                    ],
                    'totals' => [],
                ];
            }

            $byCurrency[$ccy]['sections'][$category]['accounts'][] = [
                'id'             => $account['id'],
                'code'           => $account['code'],
                'type'           => $account['type'],
                'currency_code'  => $ccy,
                'corridor'       => $account['corridor'],
                'normal_balance' => $account['normal_balance'],
                'balance'        => $balance,
            ];

            $byCurrency[$ccy]['sections'][$category]['total'] = bcadd(
                $byCurrency[$ccy]['sections'][$category]['total'],
                $balance,
                6
            );
        }

        // Compute per-currency totals and equation check
        foreach ($byCurrency as $ccy => &$data) {
            $totalAssets      = $data['sections']['asset']['total'];
            $totalLiabilities = $data['sections']['liability']['total'];
            $totalEquity      = $data['sections']['equity']['total'];
            $liabPlusEquity   = bcadd($totalLiabilities, $totalEquity, 6);

            $data['totals'] = [
                'total_assets'             => $totalAssets,
                'total_liabilities'        => $totalLiabilities,
                'total_equity'             => $totalEquity,
                'total_liabilities_equity' => $liabPlusEquity,
                'equation_balanced'        => bccomp($totalAssets, $liabPlusEquity, 6) === 0,
            ];
        }
        unset($data);

        // Overall equation: sum of all signed balances must be zero
        $allBalancesSum = '0.000000';
        foreach ($tb['accounts'] as $account) {
            $allBalancesSum = bcadd($allBalancesSum, (string) ($account['balance'] ?? '0'), 6);
        }
        $ledgerBalanced = bccomp($allBalancesSum, '0', 2) === 0;

        return [
            'generated_at'   => Carbon::now()->toIso8601String(),
            'as_of'          => $asOf ?? Carbon::now()->toDateString(),
            'currency'       => $currency,
            'by_currency'    => array_values($byCurrency),
            'ledger_balanced'=> $ledgerBalanced,
            'ledger_sum'     => $allBalancesSum,
        ];
    }
}
