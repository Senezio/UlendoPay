<?php

namespace App\Console\Commands;

use App\Models\OutboxEvent;
use App\Services\Outbox\OutboxProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessOutboxEvents extends Command
{
    protected $signature   = 'outbox:process {--limit=10}';
    protected $description = 'Process pending outbox events';

    public function __construct(private OutboxProcessor $processor)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $limit = (int) $this->option('limit');

        $events = OutboxEvent::where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')
                  ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            $this->line('[outbox] No pending events.');
            return;
        }

        $this->line("[outbox] Processing {$events->count()} event(s).");

        foreach ($events as $event) {
            $message = $this->processor->process($event);
            $this->line("[outbox] {$message}");
        }
    }
}
