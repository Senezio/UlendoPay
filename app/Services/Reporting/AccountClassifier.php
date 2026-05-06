<?php

namespace App\Services\Reporting;

/** Maps account types to financial statement categories. */
class AccountClassifier
{
    private const TYPE_MAP = [
        'user_wallet' => 'asset',
        'escrow'      => 'asset',
        'guarantee'   => 'asset',
        'fee'         => 'income',
        'partner'     => 'liability',
        'system'      => 'equity',
    ];

    private const BALANCE_SHEET_SECTIONS = ['asset', 'liability', 'equity'];

    private const PNL_SECTIONS = ['income', 'expense'];

    private const LABELS = [
        'asset'     => 'Assets',
        'liability' => 'Liabilities',
        'equity'    => 'Equity',
        'income'    => 'Revenue',
        'expense'   => 'Expenses',
    ];

    private const CASH_FLOW_MAP = [
        'transfer_initiation'     => 'operating',
        'transfer_completion'     => 'operating',
        'transfer_reversal'       => 'operating',
        'transfer_credit'         => 'operating',
        'transfer_debit'          => 'operating',
        'transfer_escrow_release' => 'operating',
        'escrow_release'          => 'operating',
        'fee_collection'          => 'operating',
        'guarantee_contribution'  => 'financing',
        'guarantee_payout'        => 'financing',
        'adjustment'              => 'operating',
    ];

    /**
     * @param  string  $accountType
     * @return string
     */
    public static function category(string $accountType): string
    {
        return self::TYPE_MAP[$accountType] ?? 'equity';
    }

    /**
     * @param  string  $category
     * @return string
     */
    public static function label(string $category): string
    {
        return self::LABELS[$category] ?? ucfirst($category);
    }

    /**
     * @param  string  $accountType
     * @return bool
     */
    public static function isBalanceSheetAccount(string $accountType): bool
    {
        return in_array(self::category($accountType), self::BALANCE_SHEET_SECTIONS, true);
    }

    /**
     * @param  string  $accountType
     * @return bool
     */
    public static function isPnlAccount(string $accountType): bool
    {
        return in_array(self::category($accountType), self::PNL_SECTIONS, true);
    }

    /**
     * @param  string  $groupType
     * @return string
     */
    public static function cashFlowActivity(string $groupType): string
    {
        return self::CASH_FLOW_MAP[$groupType] ?? 'operating';
    }

    /**
     * @return array
     */
    public static function allCategories(): array
    {
        return array_values(array_unique(array_values(self::TYPE_MAP)));
    }
}
