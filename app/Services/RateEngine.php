<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class RateEngine
{
    private string $baseUrl;
    private string $apiKey;
    private string $baseCurrency;
    private int    $expiryHours;
    private string $cacheKey = 'exchange_rates_today';

    public function __construct()
    {
        $this->baseUrl      = config('services.forexrateapi.base_url', 'https://api.forexrateapi.com/v1');
        $this->apiKey       = config('services.forexrateapi.api_key', '');
        $this->baseCurrency = config('services.forexrateapi.base_currency', 'USD');
        $this->expiryHours  = config('services.forexrateapi.expiry_hours', 14);

        if (empty($this->apiKey)) {
            throw new \RuntimeException('ForexRateAPI key is not configured.');
        }
    }

    /**
     * @return array
     */
    public function fetchAndStore(): array
    {
        $results = [
            'forexrateapi' => ['success' => false, 'count' => 0, 'error' => null],
        ];

        try {
            $rates = $this->fetchRates();
            $this->storeRates($rates);

            $results['forexrateapi'] = [
                'success' => true,
                'count'   => count($rates),
                'error'   => null,
            ];

            Log::info('[RateEngine] ForexRateAPI rates fetched', ['count' => count($rates)]);

        } catch (\Throwable $e) {
            $results['forexrateapi']['error'] = $e->getMessage();
            Log::error('[RateEngine] ForexRateAPI fetch failed', ['error' => $e->getMessage()]);
            $this->markCorridorsStale('FOREXRATEAPI', $e->getMessage());
        }

        $this->warmCache();

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
     * @param  string  $fromCurrency
     * @param  string  $toCurrency
     * @return ExchangeRate|null
     */
    public function getRate(string $fromCurrency, string $toCurrency): ?ExchangeRate
    {
        if ($fromCurrency === $toCurrency) {
            return ExchangeRate::updateOrCreate(
                ['from_currency' => $fromCurrency, 'to_currency' => $toCurrency],
                [
                    'rate'          => 1.0,
                    'inverse_rate'  => 1.0,
                    'source'        => 'SYSTEM',
                    'is_active'     => true,
                    'fetched_at'    => now(),
                    'expires_at'    => now()->addYears(10),
                ]
            );
        }

        // Try direct pair first
        $direct = ExchangeRate::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->active()
            ->latest('fetched_at')
            ->first();

        if ($direct) return $direct;

        // Fall back to USD chaining: fromCurrency → USD → toCurrency
        $base = $this->baseCurrency;
        if ($fromCurrency === $base || $toCurrency === $base) return null;

        $fromToBase = ExchangeRate::where('from_currency', $fromCurrency)
            ->where('to_currency', $base)
            ->active()
            ->latest('fetched_at')
            ->first();

        $baseToTarget = ExchangeRate::where('from_currency', $base)
            ->where('to_currency', $toCurrency)
            ->active()
            ->latest('fetched_at')
            ->first();

        if (!$fromToBase || !$baseToTarget) return null;

        $crossRate = round($fromToBase->rate * $baseToTarget->rate, 8);

        ExchangeRate::where('from_currency', $fromCurrency)->where('to_currency', $toCurrency)->where('source', 'SYNTHETIC')->update(['is_active' => false]);

        $synthetic = ExchangeRate::create(['from_currency' => $fromCurrency, 'to_currency' => $toCurrency, 'rate' => $crossRate, 'inverse_rate' => round(1 / $crossRate, 8), 'middle_rate' => $crossRate, 'margin_percent' => 0, 'source' => 'SYNTHETIC', 'is_active' => true, 'is_stale' => false, 'fetched_at' => now(), 'expires_at' => now()->addHours($this->expiryHours)]);

        return $synthetic;
    }

    /**
     * @param  string  $fromCurrency
     * @param  string  $toCurrency
     * @return bool
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
     * @return array
     */
    private function fetchRates(): array
    {
        $response = Http::withHeaders([
                'X-API-KEY'    => $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(30)
            ->get("{$this->baseUrl}/latest", [
                'base' => $this->baseCurrency,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "ForexRateAPI returned HTTP {$response->status()}"
            );
        }

        $body = $response->json();

        if (!($body['success'] ?? false)) {
            $code = $body['error']['code'] ?? 'unknown';
            $info = $body['error']['info'] ?? 'Unknown error';
            throw new \RuntimeException("ForexRateAPI error {$code}: {$info}");
        }

        $rates      = $body['rates'] ?? [];
        $fetchedAt  = now();
        $expiresAt  = now()->addHours($this->expiryHours);
        $result     = [];

        foreach ($rates as $currency => $rate) {
            if (!is_numeric($rate) || $rate <= 0) continue;

            $currency = strtoupper($currency);

            // base → currency (e.g. USD → MWK)
            $result[] = [
                'from_currency' => $this->baseCurrency,
                'to_currency'   => $currency,
                'rate'          => round($rate, 8),
                'inverse_rate'  => round(1 / $rate, 8),
                'buying_rate'   => null,
                'middle_rate'   => round($rate, 8),
                'selling_rate'  => null,
                'fetched_at'    => $fetchedAt,
                'expires_at'    => $expiresAt,
            ];

            // currency → base (e.g. MWK → USD)
            $result[] = [
                'from_currency' => $currency,
                'to_currency'   => $this->baseCurrency,
                'rate'          => round(1 / $rate, 8),
                'inverse_rate'  => round($rate, 8),
                'buying_rate'   => null,
                'middle_rate'   => round(1 / $rate, 8),
                'selling_rate'  => null,
                'fetched_at'    => $fetchedAt,
                'expires_at'    => $expiresAt,
            ];
        }

        if (empty($result)) {
            throw new \RuntimeException('ForexRateAPI returned empty rates array.');
        }

        return $result;
    }

    /**
     * @param  array  $rates
     * @return void
     */
    private function storeRates(array $rates): void
    {
        foreach ($rates as $rateData) {
            ExchangeRate::where('from_currency', $rateData['from_currency'])
                ->where('to_currency', $rateData['to_currency'])
                ->where('source', 'FOREXRATEAPI')
                ->where('is_active', true)
                ->update(['is_active' => false]);

            ExchangeRate::updateOrCreate(
                [
                    'from_currency' => $rateData['from_currency'],
                    'to_currency'   => $rateData['to_currency'],
                    'fetched_at'    => $rateData['fetched_at'],
                ],
                [
                    'from_currency' => $rateData['from_currency'],
                    'to_currency'   => $rateData['to_currency'],
                    'rate'          => $rateData['rate'],
                    'inverse_rate'  => $rateData['inverse_rate'],
                    'buying_rate'   => $rateData['buying_rate'],
                    'middle_rate'   => $rateData['middle_rate'],
                    'selling_rate'  => $rateData['selling_rate'],
                    'margin_percent'=> 0,
                    'source'        => 'FOREXRATEAPI',
                    'is_active'     => true,
                    'fetched_at'    => $rateData['fetched_at'],
                    'expires_at'    => $rateData['expires_at'],
                ]
            );
        }
    }

    /**
     * @param  string  $source
     * @param  string  $reason
     * @return void
     */
    private function markCorridorsStale(string $source, string $reason): void
    {
        ExchangeRate::where('source', $source)
            ->where('is_active', true)
            ->update([
                'is_stale'     => true,
                'stale_reason' => $reason,
            ]);
    }

    /**
     * @return void
     */
    private function warmCache(): void
    {
        Cache::forget($this->cacheKey);

        $rates = ExchangeRate::active()
            ->get()
            ->groupBy(fn($r) => "{$r->from_currency}_{$r->to_currency}");

        Cache::put($this->cacheKey, $rates, now()->addHours(2));

        foreach ($rates as $key => $corridorRates) {
            Cache::put("rate_{$key}", $corridorRates->first(), now()->addHours(1));
        }

        Log::info('[RateEngine] Cache warmed', ['corridors' => $rates->count()]);
    }
}
