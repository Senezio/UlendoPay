<?php

namespace App\Services\Outbox;

use App\Models\OutboxEvent;
use App\Models\Transaction;
use App\Services\Outbox\Contracts\OutboxHandlerInterface;
use App\Services\RefundService;

class RefundHandler implements OutboxHandlerInterface
{
    public function __construct(private RefundService $refundService) {}

    public function supports(string $eventType): bool
    {
        return $eventType === 'refund_requested';
    }

    public function handle(OutboxEvent $event): string
    {
        $transaction = Transaction::findOrFail($event->payload['transaction_id']);
        $this->refundService->refund($transaction);
        return "Refunded {$transaction->reference_number} — {$transaction->send_amount} {$transaction->send_currency} returned to sender.";
    }
}
