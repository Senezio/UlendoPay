<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\JournalEntry;
use App\Models\JournalEntryGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class LedgerService
{
    /**
     * Post a balanced set of journal entries atomically.
     *
     * $entries format:
     * [
     *   ['account_id' => 1, 'type' => 'debit',  'amount' => 10000.00],
     *   ['account_id' => 2, 'type' => 'credit', 'amount' => 9300.00],
     *   ['account_id' => 3, 'type' => 'credit', 'amount' => 500.00],
     *   ['account_id' => 4, 'type' => 'credit', 'amount' => 200.00],
     * ]
     *
     * Throws if:
     *   - Entries don't balance (debits ≠ credits)
     *   - Any account currency doesn't match group currency
     *   - Any account is inactive
     *   - Reference already posted (duplicate prevention)
     */
    public function post(
        string $reference,
        string $type,
        string $currency,
        array  $entries,
        string $description = '',
        ?int   $reversalOfGroupId = null
    ): JournalEntryGroup {

        return DB::transaction(function () use (
            $reference, $type, $currency, $entries, $description, $reversalOfGroupId
        ) {
            // ── 1. Validate entries balance ──────────────────────────────
            $totalDebits  = $this->sumSide($entries, 'debit');
            $totalCredits = $this->sumSide($entries, 'credit');

            if (bccomp((string)$totalDebits, (string)$totalCredits, 6) !== 0) {
                throw new \RuntimeException(
                    "Journal entries do not balance. " .
                    "Debits: {$totalDebits}, Credits: {$totalCredits}"
                );
            }

            // ── 2. Load and lock all accounts in ID order ────────────────
            // Always lock in consistent order to prevent deadlocks
            // when two transactions touch overlapping accounts.
            $accountIds = collect($entries)->pluck('account_id')->unique()->sort()->values();
            $accounts   = Account::whereIn('id', $accountIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($accounts as $account) {
                if (! $account->is_active) {
                    throw new \RuntimeException("Account {$account->code} is inactive.");
                }
                if ($account->currency_code !== $currency) {
                    throw new \RuntimeException(
                        "Account {$account->code} currency {$account->currency_code} " .
                        "does not match group currency {$currency}."
                    );
                }
            }

            // ── 3. Create the group ──────────────────────────────────────
            $group = JournalEntryGroup::create([
                'uuid'                  => Str::uuid(),
                'currency_code'         => $currency,
                'total_amount'          => $totalDebits,
                'type'                  => $type,
                'reference'             => $reference,
                'status'                => 'pending',
                'reversal_of_group_id'  => $reversalOfGroupId,
                'description'           => $description,
                'is_balanced'           => false,
                'posted_at'             => null,
            ]);

            // ── 4. Insert entries ────────────────────────────────────────
            $now = Carbon::now();
            foreach ($entries as $entry) {
                JournalEntry::create([
                    'group_id'      => $group->id,
                    'account_id'    => $entry['account_id'],
                    'entry_type'    => $entry['type'],
                    'amount'        => $entry['amount'],
                    'currency_code' => $currency,
                    'description'   => $entry['description'] ?? $description,
                    'posted_at'     => $now,
                ]);
            }

            // ── 5. Update account balances atomically ────────────────────
            // Lock balance rows in same ID order to prevent deadlocks
            foreach ($accountIds as $accountId) {
                $entryForAccount = collect($entries)->where('account_id', $accountId);

                $debitAmount  = $entryForAccount->where('type', 'debit')->sum('amount');
                $creditAmount = $entryForAccount->where('type', 'credit')->sum('amount');

                $account = $accounts[$accountId];

                // For normal_balance = 'debit' accounts (assets):
                //   debit increases balance, credit decreases
                // For normal_balance = 'credit' accounts (liabilities/income):
                //   credit increases balance, debit decreases
                if ($account->normal_balance === 'debit') {
                    $delta = $debitAmount - $creditAmount;
                } else {
                    $delta = $creditAmount - $debitAmount;
                }

                $balance = AccountBalance::where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrCreate(
                        ['account_id'    => $accountId],
                        ['balance'       => 0, 'currency_code' => $currency]
                    );

                $newBalance = bcadd((string)$balance->balance, (string)$delta, 6);

                // Prevent accounts going negative (except system/escrow accounts)
                if (
                    bccomp($newBalance, '0', 6) < 0 &&
                    $account->type === 'user_wallet'
                ) {
                    throw new \RuntimeException(
                        "Insufficient balance on account {$account->code}. " .
                        "Current: {$balance->balance}, Delta: {$delta}"
                    );
                }

                $balance->update([
                    'balance'             => $newBalance,
                    'last_journal_entry_id' => JournalEntry::where('group_id', $group->id)
                        ->where('account_id', $accountId)
                        ->latest('id')
                        ->value('id'),
                    'last_updated_at'     => $now,
                ]);
            }

            // ── 6. Mark group as posted ──────────────────────────────────
            $group->update([
                'is_balanced' => true,
                'status'      => 'posted',
                'posted_at'   => $now,
            ]);

            return $group;
        });
    }

    /**
     * Reverse a previously posted group.
     * Creates a new group with swapped debit/credit entries.
     */
    public function reverse(
        JournalEntryGroup $original,
        string $reference,
        string $description = ''
    ): JournalEntryGroup {

        if ($original->status !== 'posted') {
            throw new \RuntimeException("Can only reverse a posted group.");
        }

        $reversalEntries = $original->entries->map(function ($entry) {
            return [
                'account_id' => $entry->account_id,
                'type'       => $entry->entry_type === 'debit' ? 'credit' : 'debit',
                'amount'     => $entry->amount,
                'description' => 'Reversal: ' . $entry->description,
            ];
        })->toArray();

        $group = $this->post(
            reference:          $reference,
            type:               'transfer_reversal',
            currency:           $original->currency_code,
            entries:            $reversalEntries,
            description:        $description ?: "Reversal of {$original->reference}",
            reversalOfGroupId:  $original->id
        );

        // Mark original as reversed
        $original->update(['status' => 'reversed']);

        return $group;
    }

    /**
     * Return the current balance for an account.
     * Reads from account_balances (fast) not journal_entries (slow).
     */
    public function getBalance(int $accountId): string
    {
        $balance = AccountBalance::where('account_id', $accountId)->first();
        return $balance ? (string)$balance->balance : '0.000000';
    }

    private function sumSide(array $entries, string $side): float
    {
        return collect($entries)
            ->where('type', $side)
            ->sum('amount');
    }
}
