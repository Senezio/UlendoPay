<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RateEngine;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;

class FetchExchangeRates extends Command
{
    protected $signature   = 'rates:fetch';
    protected $description = 'Fetch latest exchange rates from RBM and SARB';

    public function __construct(private readonly RateEngine $rateEngine)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->line('[rates] Starting exchange rate fetch...');

        $startTime = microtime(true);

        try {
            $results = $this->rateEngine->fetchAndStore();

            $duration = round((microtime(true) - $startTime) * 1000);

            // Report results
            foreach ($results as $source => $result) {
                if ($result['success']) {
                    $this->line(
                        "[rates] {$source}: {$result['count']} rates fetched successfully."
                    );
                } else {
                    $this->warn(
                        "[rates] {$source}: FAILED — {$result['error']}"
                    );
                }
            }

            $totalRates = array_sum(array_column($results, 'count'));
            $this->line("[rates] Done. {$totalRates} total rates stored in {$duration}ms.");

            // Warn if any source failed
            $failures = array_filter($results, fn($r) => !$r['success']);

            if (!empty($failures)) {
                $this->warn(
                    '[rates] ' . count($failures) . ' source(s) failed. ' .
                    'Some corridors may use stale rates.'
                );
                return self::FAILURE;
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            Log::error('[rates] Fatal error during rate fetch', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error("[rates] Fatal error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
