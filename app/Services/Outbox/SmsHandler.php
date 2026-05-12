<?php

namespace App\Services\Outbox;

use App\Models\OutboxEvent;
use App\Services\Outbox\Contracts\OutboxHandlerInterface;
use App\Services\SmsService;

class SmsHandler implements OutboxHandlerInterface
{
    public function __construct(private SmsService $sms) {}

    public function supports(string $eventType): bool
    {
        return $eventType === 'sms_notification';
    }

    public function handle(OutboxEvent $event): string
    {
        $this->sms->send($event->payload);
        return "SMS sent for transaction: " . ($event->payload['reference'] ?? $event->payload['transaction_id']);
    }
}
