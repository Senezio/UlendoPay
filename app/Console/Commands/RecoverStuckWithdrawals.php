<?php

namespace App\Console\Commands;

use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverStuckWithdrawals extends Command
{
    protected $signature   = 'withdrawals:recover';
    protected $description = 'Refund withdrawals stuck in initiated state for more than 15 minutes';

    public function handle(WithdrawalService $withdrawalService): int
    {
        $stuck = Withdrawal::where('status', 'initiated')
            ->where('initiated_at', '<', now()->subMinutes(15))
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck withdrawals found.');
            return self::SUCCESS;
        }

        $this->info("Found {$stuck->count()} stuck withdrawal(s)...");

        foreach ($stuck as $withdrawal) {
            try {
                $withdrawalService->refundStuck($withdrawal);
                $this->info("✓ Refunded withdrawal {$withdrawal->reference}");
                Log::info('[RecoverStuckWithdrawals] Refunded', ['reference' => $withdrawal->reference]);
            } catch (\Throwable $e) {
                $this->error("✗ Failed to refund {$withdrawal->reference}: {$e->getMessage()}");
                Log::error('[RecoverStuckWithdrawals] Failed', [
                    'reference' => $withdrawal->reference,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
