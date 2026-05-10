<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;

class ScreenActiveUsers extends Command
{
    protected $signature = 'compliance:daily-screen';
    protected $description = 'Re-screen all active users against updated sanctions/PEP lists';

    public function handle(): int
    {
        $count = 0;

        User::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('last_screened_at')
                  ->orWhere('last_screened_at', '<', now()->subDay());
            })
            ->select('id')
            ->cursor()
            ->each(function (User $user) use (&$count) {
                OutboxEvent::create([
                    'event_type'      => 'compliance_screening',
                    'payload'         => ['user_id' => $user->id, 'trigger' => 'daily_job'],
                    'status'          => 'pending',
                    'next_attempt_at' => now(),
                    'max_attempts'    => 3,
                ]);
                $count++;
            });

        $this->info("Queued {$count} users for compliance screening.");
        return 0;
    }
}
