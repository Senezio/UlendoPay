<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Compliance\ComplianceService;
use Illuminate\Console\Command;

class ScreenActiveUsers extends Command
{
    protected $signature = 'compliance:daily-screen';
    protected $description = 'Re-screen all active users against updated sanctions/PEP lists';

    public function handle(ComplianceService $service): int
    {
        $users = User::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('last_screened_at')
                  ->orWhere('last_screened_at', '<', now()->subDay());
            })
            ->cursor();

        $count = 0;
        foreach ($users as $user) {
            $service->fullScreen($user, 'daily_job');
            $count++;
        }

        $this->info("Screened {$count} active users.");
        return 0;
    }
}
