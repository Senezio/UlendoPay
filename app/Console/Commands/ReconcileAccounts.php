<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\JournalEntry;
use App\Models\ReconciliationSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ReconcileAccounts extends Command
{
    protected $signature   = 'reconcile:accounts {--date= : Date to reconcile (Y-m-d), defaults to yesterday}';
    protected $description = 'Snapshot all account balances and flag any mismatches';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();

        $this->info("Reconciling accounts for {$date->toDateString()}...");

        $accounts  = Account::where('is_active', true)->get();
        $mismatches = 0;

        foreach ($accounts as $account) {
            try {
                $this->reconcileAccount($account, $date);
            } catch (\Throwable $e) {
                $this->error("Failed to reconcile account {$account->code}: {$e->getMessage()}");
                $mismatches++;
            }
        }

        $this->info("Reconciliation complete. Mismatches: {$mismatches}");
        return $mismatches > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function reconcileAccount(Account $account, Carbon $date): void
    {
        // Computed balance = sum of all journal entries up to end of date
        $endOfDay = $date->copy()->endOfDay();

        $totalDebits = JournalEntry::where('account_id', $account->id)
            ->where('entry_type', 'debit')
            ->where('posted_at', '<=', $endOfDay)
            ->sum('amount');

        $totalCredits = JournalEntry::where('account_id', $account->id)
            ->where('entry_type', 'credit')
            ->where('posted_at', '<=', $endOfDay)
            ->sum('amount');

        // For debit-normal accounts: balance = debits - credits
        // For credit-normal accounts: balance = credits - debits
        if ($account->normal_balance === 'debit') {
            $computedBalance = bcsub((string)$totalDebits, (string)$totalCredits, 6);
        } else {
            $computedBalance = bcsub((string)$totalCredits, (string)$totalDebits, 6);
        }

        // Expected = current balance in account_balances
        $currentBalance  = AccountBalance::where('account_id', $account->id)
            ->value('balance') ?? '0.000000';

        $variance = bcsub((string)$computedBalance, (string)$currentBalance, 6);
        $matched  = bccomp((string)$variance, '0', 6) === 0;

        ReconciliationSnapshot::updateOrCreate(
            [
                'account_id'    => $account->id,
                'snapshot_date' => $date->toDateString(),
            ],
            [
                'computed_balance' => $computedBalance,
                'expected_balance' => $currentBalance,
                'variance'         => $variance,
                'status'           => $matched ? 'matched' : 'mismatch',
            ]
        );

        if (! $matched) {
            $this->warn(
                "MISMATCH on account {$account->code}: " .
                "computed={$computedBalance}, expected={$currentBalance}, variance={$variance}"
            );
        }
    }
}
