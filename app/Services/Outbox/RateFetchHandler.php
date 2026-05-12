<?php

namespace App\Services\Outbox;

use App\Models\OutboxEvent;
use App\Services\Outbox\Contracts\OutboxHandlerInterface;
use App\Services\RateEngine;
use Illuminate\Support\Facades\Log;

class RateFetchHandler implements OutboxHandlerInterface
{
    public function __construct(private RateEngine $rateEngine) {}

    public function supports(string $eventType): bool
    {
        return $eventType === 'rate_fetch_requested';
    }

    public function handle(OutboxEvent $event): string
    {
        $results = $this->rateEngine->fetchAndStore();

        $totalRates = array_sum(array_column($results, 'count'));
        $failures   = array_filter($results, fn($r) => !$r['success']);

        if (!empty($failures)) {
            $sources = implode(', ', array_keys($failures));
            throw new \RuntimeException("Rate fetch failed for sources: {$sources}");
        }

        Log::info('[RateFetchHandler] Rates fetched successfully', ['total' => $totalRates]);

        return "Fetched {$totalRates} exchange rates successfully.";
    }
}
