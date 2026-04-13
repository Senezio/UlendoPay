<?php

namespace App\Console\Commands;

use App\Services\ReconciliationService;
use Illuminate\Console\Command;

class ReconcileAccounts extends Command
{
    protected $signature   = 'reconcile:accounts';
    protected $description = 'Run daily reconciliation — compute account balances and flag variances';

    public function handle(ReconciliationService $reconciliation): int
    {
        $this->info('Running daily reconciliation...');

        $results = $reconciliation->runDaily();

        $this->table(
            ['Date', 'Total', 'Matched', 'Mismatch', 'Errors'],
            [[$results['date'], $results['total'], $results['matched'], $results['mismatch'], $results['errors']]]
        );

        if ($results['mismatch'] > 0) {
            $this->warn("⚠  {$results['mismatch']} account(s) have variances. Check reconciliation_snapshots table.");
            return self::FAILURE;
        }

        $this->info('✓ All accounts reconciled successfully.');
        return self::SUCCESS;
    }
}