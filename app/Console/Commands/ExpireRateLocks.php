<?php

namespace App\Console\Commands;

use App\Models\RateLock;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ExpireRateLocks extends Command
{
    protected $signature   = 'rate-locks:expire';
    protected $description = 'Mark expired rate locks as expired';

    public function handle(): int
    {
        $count = RateLock::where('status', 'active')
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => 'expired']);

        $this->info("Expired {$count} rate lock(s).");
        return self::SUCCESS;
    }
}
