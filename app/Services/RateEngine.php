<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class RateEngine
{
    // African currencies RBM publishes directly
    private array $africanCurrencies = [
        'BWP', 'ETB', 'KES', 'MGA', 'MZN', 'TZS', 'ZAR', 'ZMW'
    ];

    // Rate validity window — 26 hours gives RBM time to publish next day
    private int $expiryHours = 26;

    // Cache key for today's rates
    private string $cacheKey = 'exchange_rates_today';

    /**
     * Main entry point — fetch all rates and store them.
     * Called by the daily scheduler.
     */
    public function fetchAndStore(): array
    {
        $results = [
            'rbm'           => ['success' => false, 'count' => 0, 'error' => null],
            'sarb'          => ['success' => false, 'count' => 0, 'error' => null],
            'triangulated'  => ['success' => false, 'count' => 0, 'error' => null],
        ];

        // Step 1 — Fetch RBM rates (primary source)
        try {
            $rbmRates = $this->fetchRbmRates();
            $this->storeRates($rbmRates, 'RBM');
            $results['rbm'] = ['success' => true, 'count' => count($rbmRates), 'error' => null];

            Log::info('[RateEngine] RBM rates fetched', ['count' => count($rbmRates)]);

        } catch (\Throwable $e) {
            $results['rbm']['error'] = $e->getMessage();
            Log::error('[RateEngine] RBM fetch failed', ['error' => $e->getMessage()]);
            $this->markCorridorsStale('RBM', $e->getMessage());
        }

        // Step 2 — Fetch SARB rates (verification layer for ZAR)
        try {
            $sarbRates = $this->fetchSarbRates();
            $this->storeRates($sarbRates, 'SARB');
            $results['sarb'] = ['success' => true, 'count' => count($sarbRates), 'error' => null];

            Log::info('[RateEngine] SARB rates fetched', ['count' => count($sarbRates)]);

        } catch (\Throwable $e) {
            $results['sarb']['error'] = $e->getMessage();
            Log::warning('[RateEngine] SARB fetch failed — ZAR verification unavailable', [
                'error' => $e->getMessage()
            ]);
        }

        // Step 3 — Calculate triangulated cross-currency pairs
        if ($results['rbm']['success']) {
            try {
                $triangulated = $this->calculateTriangulatedRates();
                $this->storeRates($triangulated, 'TRIANGULATED');
                $results['triangulated'] = [
                    'success' => true,
                    'count'   => count($triangulated),
                    'error'   => null
                ];

                Log::info('[RateEngine] Triangulated rates calculated', [
                    'count' => count($triangulated)
                ]);

            } catch (\Throwable $e) {
                $results['triangulated']['error'] = $e->getMessage();
                Log::error('[RateEngine] Triangulation failed', ['error' => $e->getMessage()]);
            }
        }

        // Step 4 — Warm the cache with today's rates
        $this->warmCache();

        // Step 5 — Audit log
        AuditLog::create([
            'user_id'     => null,
            'action'      => 'rates.fetched',
            'entity_type' => 'ExchangeRate',
            'entity_id'   => 'daily',
            'new_values'  => $results,
        ]);

        return $results;
    }

    /**
     * Get the current active rate for a corridor.
     * Serves from cache — never hits DB per transaction.
     */
    public function getRate(string $fromCurrency, string $toCurrency): ?ExchangeRate
    {
        $cacheKey = "rate_{$fromCurrency}_{$toCurrency}";

        if ($fromCurrency === $toCurrency) { return ExchangeRate::updateOrCreate(["from_currency" => $fromCurrency, "to_currency" => $toCurrency], ["rate" => 1.0, "inverse_rate" => 1.0, "source" => "SYSTEM", "is_active" => true, "fetched_at" => now(), "expires_at" => now()->addYears(10)]); }

        return Cache::remember($cacheKey, now()->addHours(1), function () use ($fromCurrency, $toCurrency) {
            return ExchangeRate::where('from_currency', $fromCurrency)
                ->where('to_currency', $toCurrency)
                ->active()
                ->latest('fetched_at')
                ->first();
        });
    }

    /**
     * Check if rates are fresh for a corridor.
     * Used by TransactionService before creating a rate lock.
     */
    public function isRateFresh(string $fromCurrency, string $toCurrency): bool
    {
        $rate = $this->getRate($fromCurrency, $toCurrency);

        if (!$rate) return false;
        if ($rate->is_stale) return false;
        if ($rate->expires_at->isPast()) return false;

        return true;
    }

    /**
     * Fetch rates from Reserve Bank of Malawi HTML page.
     * Parses the exchange rates table directly.
     */
    private function fetchRbmRates(): array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36'
        ])
        ->timeout(30)
        ->get('https://www.rbm.mw/Statistics/MajorRates/');

        if (!$response->successful()) {
            throw new \RuntimeException(
                "RBM returned HTTP {$response->status()}"
            );
        }

        return $this->parseRbmHtml($response->body());
    }

    /**
     * Parse RBM HTML table into structured rate array.
     */
    private function parseRbmHtml(string $html): array
    {
        $rates = [];

        // Extract table rows
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $rows);

        foreach ($rows[1] as $row) {
            // Extract cell content
            preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $row, $cells);
            $cellTexts = array_map(fn($c) => trim(strip_tags($c)), $cells[1]);

            if (count($cellTexts) < 4) continue;

            $currency = strtoupper(trim($cellTexts[0]));

            if (!in_array($currency, $this->africanCurrencies)) continue;

            $buying  = $this->parseDecimal($cellTexts[1]);
            $middle  = $this->parseDecimal($cellTexts[2]);
            $selling = $this->parseDecimal($cellTexts[3]);

            if (!$buying || !$middle || !$selling) continue;

            // RBM rates are MWK per 1 unit of foreign currency
            // e.g. 1 TZS = 0.6747 MWK
            // For our system: from_currency=MWK, to_currency=TZS
            // rate = 1 / middle (how many TZS per 1 MWK)
            $rates[] = [
                'from_currency' => 'MWK',
                'to_currency'   => $currency,
                'rate'          => round(1 / $middle, 8),
                'inverse_rate'  => round($middle, 8),
                'buying_rate'   => round($buying, 6),
                'middle_rate'   => round($middle, 6),
                'selling_rate'  => round($selling, 6),
                'fetched_at'    => now(),
                'expires_at'    => now()->addHours($this->expiryHours),
            ];

            // Also store the reverse pair
            // e.g. TZS → MWK
            $rates[] = [
                'from_currency' => $currency,
                'to_currency'   => 'MWK',
                'rate'          => round($middle, 8),
                'inverse_rate'  => round(1 / $middle, 8),
                'buying_rate'   => round($buying, 6),
                'middle_rate'   => round($middle, 6),
                'selling_rate'  => round($selling, 6),
                'fetched_at'    => now(),
                'expires_at'    => now()->addHours($this->expiryHours),
            ];
        }

        if (empty($rates)) {
            throw new \RuntimeException(
                'RBM HTML parsed successfully but no African currency rates found. ' .
                'Page structure may have changed.'
            );
        }

        return $rates;
    }

    /**
     * Fetch rates from South African Reserve Bank JSON API.
     * Used as verification layer for ZAR pairs.
     */
    private function fetchSarbRates(): array
    {
        $response = Http::timeout(15)
            ->get('https://custom.resbank.co.za/SarbWebApi/WebIndicators/HomePageRates');

        if (!$response->successful()) {
            throw new \RuntimeException(
                "SARB API returned HTTP {$response->status()}"
            );
        }

        $data  = $response->json();
        $rates = [];

        foreach ($data as $item) {
            if (($item['SectionId'] ?? '') !== 'HPREXCH') continue;

            $name  = $item['Name'] ?? '';
            $value = $item['Value'] ?? null;
            $date  = $item['Date'] ?? null;

            if (!$value || !$date) continue;

            // SARB publishes ZAR per foreign currency
            // e.g. "Rand per US Dollar" = 16.4268 means 1 USD = 16.4268 ZAR
            $foreignCurrency = match(true) {
                str_contains($name, 'US Dollar')      => 'USD',
                str_contains($name, 'British Pound')  => 'GBP',
                str_contains($name, 'Euro')            => 'EUR',
                str_contains($name, 'Japanese Yen')   => 'JPY',
                default                                => null,
            };

            if (!$foreignCurrency) continue;

            // ZAR → foreign currency
            $rates[] = [
                'from_currency' => 'ZAR',
                'to_currency'   => $foreignCurrency,
                'rate'          => round(1 / $value, 8),
                'inverse_rate'  => round($value, 8),
                'buying_rate'   => null,
                'middle_rate'   => round($value, 6),
                'selling_rate'  => null,
                'fetched_at'    => Carbon::parse($date),
                'expires_at'    => now()->addHours($this->expiryHours),
            ];
        }

        return $rates;
    }

    /**
     * Calculate cross-currency pairs via MWK triangulation.
     * Every African pair not directly published by RBM
     * is derived from two RBM rates.
     *
     * Formula: rate(A→B) = rate(A→MWK) / rate(B→MWK)
     * Example: TZS→KES = (TZS→MWK) / (KES→MWK)
     *        = 0.6747 / 13.3797
     *        = 0.0504 KES per 1 TZS
     */
    private function calculateTriangulatedRates(): array
    {
        $triangulated = [];
        $today        = now()->toDateString();

        // Fetch today's RBM rates for all African currencies
        $rbmRates = ExchangeRate::where('source', 'RBM')
            ->whereDate('fetched_at', $today)
            ->where('from_currency', 'MWK')
            ->whereIn('to_currency', $this->africanCurrencies)
            ->get()
            ->keyBy('to_currency');

        if ($rbmRates->count() < 2) {
            throw new \RuntimeException(
                'Insufficient RBM rates for triangulation. ' .
                "Found {$rbmRates->count()}, need at least 2."
            );
        }

        // Generate all cross pairs
        foreach ($this->africanCurrencies as $fromCurrency) {
            foreach ($this->africanCurrencies as $toCurrency) {
                if ($fromCurrency === $toCurrency) continue;

                $fromRate = $rbmRates->get($fromCurrency); // MWK per 1 fromCurrency
                $toRate   = $rbmRates->get($toCurrency);   // MWK per 1 toCurrency

                if (!$fromRate || !$toRate) continue;

                // Skip if direct rate already exists from RBM
                $directExists = ExchangeRate::where('from_currency', $fromCurrency)
                    ->where('to_currency', $toCurrency)
                    ->where('source', 'RBM')
                    ->whereDate('fetched_at', $today)
                    ->exists();

                if ($directExists) continue;

                // fromCurrency → MWK = inverse_rate of MWK→fromCurrency
                $fromToMwk = $fromRate->inverse_rate; // e.g. TZS→MWK = 0.6747
                $toToMwk   = $toRate->inverse_rate;   // e.g. KES→MWK = 13.3797

                // Rate: how many toCurrency per 1 fromCurrency
                $crossRate = round($fromToMwk / $toToMwk, 8);

                if ($crossRate <= 0) continue;

                $triangulated[] = [
                    'from_currency' => $fromCurrency,
                    'to_currency'   => $toCurrency,
                    'rate'          => $crossRate,
                    'inverse_rate'  => round(1 / $crossRate, 8),
                    'buying_rate'   => null,
                    'middle_rate'   => $crossRate,
                    'selling_rate'  => null,
                    'fetched_at'    => now(),
                    'expires_at'    => now()->addHours($this->expiryHours),
                ];
            }
        }

        return $triangulated;
    }

    /**
     * Store rates in database.
     * Deactivates previous rates for same corridor before inserting new ones.
     */
    private function storeRates(array $rates, string $source): void
    {
        foreach ($rates as $rateData) {
            // Deactivate previous rates for this corridor and source
            ExchangeRate::where('from_currency', $rateData['from_currency'])
                ->where('to_currency', $rateData['to_currency'])
                ->where('source', $source)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            // Insert new rate
            ExchangeRate::updateOrCreate(["from_currency" => $rateData["from_currency"], "to_currency" => $rateData["to_currency"], "fetched_at" => $rateData["fetched_at"]], [
                'from_currency' => $rateData['from_currency'],
                'to_currency'   => $rateData['to_currency'],
                'rate'          => $rateData['rate'],
                'inverse_rate'  => $rateData['inverse_rate'],
                'buying_rate'   => $rateData['buying_rate'] ?? null,
                'middle_rate'   => $rateData['middle_rate'] ?? null,
                'selling_rate'  => $rateData['selling_rate'] ?? null,
                'margin_percent'=> 0,
                'source'        => $source,
                'is_active'     => true,
                'fetched_at'    => $rateData['fetched_at'],
                'expires_at'    => $rateData['expires_at'],
            ]);
        }
    }

    /**
     * Mark all active rates for a source as stale.
     * Called when a fetch fails so the system knows
     * not to trust current rates.
     */
    private function markCorridorsStale(string $source, string $reason): void
    {
        ExchangeRate::where('source', $source)
            ->where('is_active', true)
            ->update([
                'is_stale'      => true,
                'stale_reason'  => $reason,
            ]);
    }

    /**
     * Warm the rate cache after a successful fetch.
     * All subsequent rate lookups serve from cache.
     */
    private function warmCache(): void
    {
        Cache::forget($this->cacheKey);

        $rates = ExchangeRate::active()
            ->get()
            ->groupBy(fn($r) => "{$r->from_currency}_{$r->to_currency}");

        Cache::put($this->cacheKey, $rates, now()->addHours(2));

        // Also cache individual corridor rates
        foreach ($rates as $key => $corridorRates) {
            Cache::put(
                "rate_{$key}",
                $corridorRates->first(),
                now()->addHours(1)
            );
        }

        Log::info('[RateEngine] Cache warmed', ['corridors' => $rates->count()]);
    }

    /**
     * Parse a decimal string from HTML — handles commas and whitespace.
     */
    private function parseDecimal(string $value): ?float
    {
        $cleaned = trim(str_replace(',', '', strip_tags($value)));

        if (!is_numeric($cleaned)) return null;

        $float = (float) $cleaned;

        return $float > 0 ? $float : null;
    }
}
