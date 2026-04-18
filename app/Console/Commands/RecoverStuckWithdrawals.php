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
        // Handle initiated withdrawals stuck for > 15 minutes — refund immediately
        $initiatedStuck = Withdrawal::where('status', 'initiated')
            ->where('initiated_at', '<', now()->subMinutes(15))
            ->get();

        // Handle pending withdrawals stuck for > 60 minutes — PawaPay webhook never arrived
        $pendingStuck = Withdrawal::where('status', 'pending')
            ->where('updated_at', '<', now()->subMinutes(60))
            ->get();

        $stuck = $initiatedStuck->merge($pendingStuck);

        if ($stuck->isEmpty()) {
            $this->info('No stuck withdrawals found.');
            return self::SUCCESS;
        }

        $this->info("Found {$stuck->count()} stuck withdrawal(s)...");

        foreach ($stuck as $withdrawal) {
            try {
                if ($withdrawal->status === 'initiated') {
                    $withdrawalService->refundStuck($withdrawal);
                    $this->info("✓ Refunded initiated withdrawal {$withdrawal->reference}");
                    Log::info('[RecoverStuckWithdrawals] Refunded initiated', ['reference' => $withdrawal->reference]);
                } elseif ($withdrawal->status === 'pending') {
                    // Pending = PawaPay accepted but webhook never arrived — refund safely
                    $withdrawalService->refundPendingStuck($withdrawal);
                    $this->info("✓ Refunded pending withdrawal {$withdrawal->reference}");
                    Log::info('[RecoverStuckWithdrawals] Refunded pending', ['reference' => $withdrawal->reference]);
                }
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
