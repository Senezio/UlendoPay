<?php

namespace App\Services\Outbox;

use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Compliance\ComplianceService;
use App\Services\Outbox\Contracts\OutboxHandlerInterface;

class ComplianceScreeningHandler implements OutboxHandlerInterface
{
    public function __construct(private ComplianceService $compliance) {}

    public function supports(string $eventType): bool
    {
        return $eventType === 'compliance_screening';
    }

    public function handle(OutboxEvent $event): string
    {
        $userId  = $event->payload['user_id'] ?? null;
        $trigger = $event->payload['trigger'] ?? 'daily_job';

        if (! $userId) {
            throw new \RuntimeException('compliance_screening event missing user_id');
        }

        $user = User::find($userId);

        if (! $user) {
            throw new \RuntimeException("User {$userId} not found for compliance screening");
        }

        if (in_array($user->status, ['suspended', 'closed'])) {
            return "Compliance screening skipped for user {$userId} — status: {$user->status}";
        }

        $this->compliance->fullScreen($user, $trigger);
        return "Compliance screening complete for user {$userId}";
    }
}
