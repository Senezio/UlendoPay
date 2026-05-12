<?php

namespace App\Services\Outbox;

use App\Models\OutboxEvent;
use App\Services\Outbox\Contracts\OutboxHandlerInterface;
use App\Services\ReconciliationService;

class ReconciliationHandler implements OutboxHandlerInterface
{
    public function __construct(private ReconciliationService $reconciliation) {}

    public function supports(string $eventType): bool
    {
        return $eventType === 'reconciliation_triggered';
    }

    public function handle(OutboxEvent $event): string
    {
        $results = $this->reconciliation->runDaily();

        $message = sprintf(
            'Reconciliation complete for %s - total: %d, matched: %d, mismatch: %d, errors: %d',
            $results['date'],
            $results['total'],
            $results['matched'],
            $results['mismatch'],
            $results['errors']
        );

        if ($results['mismatch'] > 0 || $results['errors'] > 0) {
            throw new \RuntimeException($message . ' — check reconciliation_snapshots table.');
        }

        return $message;
    }
}
