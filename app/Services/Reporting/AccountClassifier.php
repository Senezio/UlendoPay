<?php

namespace App\Services\Reporting;

/**
 * Single source of truth for mapping account.type → financial statement category.
 *
 * Classification rationale:
 *
 * This is a LIABILITY-CENTRIC ledger. The platform records what it owes,
 * not what it holds. Real assets (partner receivables, bank accounts) are
 * tracked outside this ledger until the asset accounting layer is built.
 *
 * user_wallet  → liability  Money owed back to users
 * escrow       → liability  Funds held in transit for pending transfers
 * guarantee    → liability  Risk reserve obligations per corridor
 * partner      → liability  Settlement obligations to disbursement partners
 * fee          → income     Platform revenue earned on transfers
 * system       → split by code:
 *   {CCY}-POOL    → liability  Internal transit/holding account
 *   {CCY}-EQUITY  → equity     Source of float capital / retained earnings
 *
 * Balance Sheet equation: Assets = Liabilities + Equity
 * In this ledger: Assets = 0 (no asset accounts yet)
 * Liabilities + Equity must sum to zero for the books to balance.
 *
 * Cash Flow:
 *   Operating:  transfers, fees, escrow operations
 *   Financing:  guarantee contributions and payouts
 *   Investing:  (none currently)
 */
class AccountClassifier
{
    // Maps account.type → statement category
    // Note: 'system' type requires code-level inspection — see categoryForAccount()
    private const TYPE_MAP = [
        'user_wallet' => 'liability',
        'escrow'      => 'liability',
        'guarantee'   => 'liability',
        'partner'     => 'liability',
        'fee'         => 'income',
        'system'      => 'equity',   // default; POOL accounts override to 'liability'
    ];

    // Balance sheet sections
    private const BALANCE_SHEET_SECTIONS = ['asset', 'liability', 'equity'];

    // P&L sections
    private const PNL_SECTIONS = ['income', 'expense'];

    // Human-readable labels
    private const LABELS = [
        'asset'     => 'Assets',
        'liability' => 'Liabilities',
        'equity'    => 'Equity',
        'income'    => 'Revenue',
        'expense'   => 'Expenses',
    ];

    // Maps journal_entry_groups.type → cash flow activity
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
     * Classify by account type only.
     * For system accounts, use categoryForAccount() to get code-aware classification.
     */
    public static function category(string $accountType): string
    {
        return self::TYPE_MAP[$accountType] ?? 'equity';
    }

    /**
     * Classify by both account type and code.
     * Use this when you have the full account record.
     *
     * System accounts are split:
     *   {CCY}-POOL   → liability  (internal holding, offsets user wallet liabilities)
     *   {CCY}-EQUITY → equity     (source of float capital)
     */
    public static function categoryForAccount(string $accountType, string $accountCode): string
    {
        if ($accountType === 'system') {
            if (str_ends_with($accountCode, '-POOL')) {
                return 'liability';
            }
            if (str_ends_with($accountCode, '-EQUITY')) {
                return 'equity';
            }
            return 'equity'; // safe fallback for any other system accounts
        }

        return self::TYPE_MAP[$accountType] ?? 'equity';
    }

    public static function label(string $category): string
    {
        return self::LABELS[$category] ?? ucfirst($category);
    }

    public static function isBalanceSheetAccount(string $accountType): bool
    {
        return in_array(self::category($accountType), self::BALANCE_SHEET_SECTIONS, true);
    }

    public static function isPnlAccount(string $accountType): bool
    {
        return in_array(self::category($accountType), self::PNL_SECTIONS, true);
    }

    public static function cashFlowActivity(string $groupType): string
    {
        return self::CASH_FLOW_MAP[$groupType] ?? 'operating';
    }

    public static function allCategories(): array
    {
        return array_values(array_unique(array_values(self::TYPE_MAP)));
    }
}
