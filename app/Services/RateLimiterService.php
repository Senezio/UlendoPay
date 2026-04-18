<?php

namespace App\Services;

use App\Models\RateLimitBucket;
use Illuminate\Support\Facades\DB;

class RateLimiterService
{
    /**
     * Action config: [max_attempts, window_minutes, block_minutes]
     */
    private array $config = [
        'otp.request'         => [5,  10, 15],
        'login'               => [10, 15, 15],
        'topup.initiate'      => [20, 60, 30],
        'withdrawal.initiate' => [20, 60, 30],
        'transfer.initiate'   => [20, 60, 30],
        'kyc.submit'          => [5,  60, 30],
        'pin.change'          => [5,  60, 30],
    ];

    public function attempt(string $key, string $action): void
    {
        if (!isset($this->config[$action])) {
            return;
        }

        [$maxAttempts, $windowMinutes, $blockMinutes] = $this->config[$action];

        DB::transaction(function () use ($key, $action, $maxAttempts, $windowMinutes, $blockMinutes) {
            $bucket = RateLimitBucket::where('key', $key)
                ->where('action', $action)
                ->lockForUpdate()
                ->first();

            if (!$bucket) {
                RateLimitBucket::create([
                    'key'          => $key,
                    'action'       => $action,
                    'attempts'     => 1,
                    'window_start' => now(),
                    'created_at'   => now(),
                ]);
                return;
            }

            if ($bucket->isBlocked()) {
                abort(429, 'Too many attempts. Please try again later.');
            }

            if ($bucket->isWindowExpired($windowMinutes)) {
                $bucket->update([
                    'attempts'      => 1,
                    'window_start'  => now(),
                    'blocked_until' => null,
                ]);
                return;
            }

            $attempts = $bucket->attempts + 1;

            if ($attempts >= $maxAttempts) {
                $bucket->update([
                    'attempts'      => $attempts,
                    'blocked_until' => now()->addMinutes($blockMinutes),
                ]);
                abort(429, 'Too many attempts. Please try again later.');
            }

            $bucket->update(['attempts' => $attempts]);
        });
    }

    public function clear(string $key, string $action): void
    {
        RateLimitBucket::where('key', $key)
            ->where('action', $action)
            ->delete();
    }
}
