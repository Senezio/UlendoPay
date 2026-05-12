<?php

namespace App\Services\Outbox;

use App\Models\OutboxEvent;
use App\Services\Outbox\Contracts\OutboxHandlerInterface;
use Illuminate\Support\Facades\Log;

class OutboxProcessor
{
    private array $handlers;

    public function __construct(
        DisbursementHandler       $disbursement,
        InternalSettlementHandler $settlement,
        RefundHandler             $refund,
        SmsHandler                $sms,
        ComplianceScreeningHandler $compliance,
        ReconciliationHandler     $reconciliation,
    ) {
        $this->handlers = [
            $disbursement,
            $settlement,
            $refund,
            $sms,
            $compliance,
            $reconciliation,
        ];
    }

    public function process(OutboxEvent $event): string
    {
        $event->update(['status' => 'processing']);

        try {
            $handler = $this->resolveHandler($event->event_type);
            $message = $handler->handle($event);

            $event->update([
                'status'       => 'completed',
                'processed_at' => now(),
            ]);

            return $message;

        } catch (\Throwable $e) {
            return $this->handleFailure($event, $e);
        }
    }

    private function resolveHandler(string $eventType): OutboxHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($eventType)) {
                return $handler;
            }
        }

        throw new \RuntimeException("Unknown event type: {$eventType}");
    }

    private function handleFailure(OutboxEvent $event, \Throwable $e): string
    {
        $permanentErrors = ['PARAMETER_INVALID', 'INVALID_PARAMETER', 'VALIDATION_ERROR'];
        $isPermanent     = collect($permanentErrors)
            ->contains(fn($code) => str_contains($e->getMessage(), $code));

        if ($isPermanent) {
            $event->update([
                'status'         => 'failed',
                'attempts'       => $event->max_attempts,
                'failure_reason' => $e->getMessage(),
            ]);

            Log::error('[outbox] Permanent error — no retry', [
                'event_id' => $event->id,
                'error'    => $e->getMessage(),
            ]);

            if ($event->event_type === 'disbursement_requested') {
                $this->resolveDisbursementHandler()->queueRefundForFailedDisbursement($event);
            }

            return "Event {$event->id} permanently failed: {$e->getMessage()}";
        }

        $attempts = $event->attempts + 1;
        $failed   = $attempts >= $event->max_attempts;

        $event->update([
            'status'          => $failed ? 'failed' : 'pending',
            'attempts'        => $attempts,
            'failure_reason'  => $e->getMessage(),
            'next_attempt_at' => $failed
                ? null
                : now()->addSeconds(30 * pow(2, $attempts - 1)),
        ]);

        Log::error("[outbox] Event {$event->id} failed", [
            'event_type' => $event->event_type,
            'attempt'    => $attempts,
            'error'      => $e->getMessage(),
            'trace'      => $e->getTraceAsString(),
        ]);

        if ($failed && $event->event_type === 'disbursement_requested') {
            $this->resolveDisbursementHandler()->queueRefundForFailedDisbursement($event);
        }

        $status = $failed ? 'failed' : "attempt {$attempts}/{$event->max_attempts}";
        return "Event {$event->id} ({$event->event_type}) {$status}: {$e->getMessage()}";
    }

    private function resolveDisbursementHandler(): DisbursementHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler instanceof DisbursementHandler) {
                return $handler;
            }
        }

        throw new \RuntimeException('DisbursementHandler not registered.');
    }
}
