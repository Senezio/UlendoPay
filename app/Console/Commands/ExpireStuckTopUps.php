<?php

namespace App\Console\Commands;

use App\Models\TopUp;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireStuckTopUps extends Command
{
    protected $signature   = 'topups:expire';
    protected $description = 'Mark pending top-ups as failed if no webhook received within 3 minutes';

    public function handle(): int
    {
        $stuck = TopUp::where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(3))
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck top-ups found.');
            return self::SUCCESS;
        }

        $this->info("Found {$stuck->count()} stuck top-up(s)...");

        foreach ($stuck as $topUp) {
            try {
                $topUp->update([
                    'status'         => 'failed',
                    'failure_reason' => 'TIMED_OUT',
                    'failed_at'      => now(),
                ]);

                OutboxEvent::create([
                    'event_type'     => 'sms_notification',
                    'transaction_id' => null,
                    'payload'        => [
                        'type'      => 'topup_failed',
                        'phone'     => $topUp->phone_number,
                        'amount'    => $topUp->amount,
                        'currency'  => $topUp->currency_code,
                        'reference' => $topUp->reference,
                        'reason'    => 'Payment request timed out.',
                    ],
                    'status' => 'pending',
                ]);

                $this->info("Failed: {$topUp->reference}");
                Log::info('[ExpireStuckTopUps] Marked as failed', ['reference' => $topUp->reference]);

            } catch (\Throwable $e) {
                $this->error("Failed to expire {$topUp->reference}: {$e->getMessage()}");
                Log::error('[ExpireStuckTopUps] Error', [
                    'reference' => $topUp->reference,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
