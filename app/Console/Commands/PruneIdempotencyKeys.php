<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneIdempotencyKeys extends Command
{
    protected $signature   = 'idempotency:prune {--days=7 : Delete keys older than this many days}';
    protected $description = 'Delete expired idempotency keys';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $count = IdempotencyKey::where('expires_at', '<', Carbon::now())
            ->whereIn('status', ['completed', 'failed'])
            ->delete();

        $this->info("Pruned {$count} idempotency key(s) older than {$days} days.");
        return self::SUCCESS;
    }
}
