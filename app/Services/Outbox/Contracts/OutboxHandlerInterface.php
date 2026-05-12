<?php

namespace App\Services\Outbox\Contracts;

use App\Models\OutboxEvent;

interface OutboxHandlerInterface
{
    public function handle(OutboxEvent $event): string;
    public function supports(string $eventType): bool;
}
