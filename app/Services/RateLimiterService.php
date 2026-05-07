<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed rate limiter.
 *
 * Replaces the previous DB/lockForUpdate implementation.
 * Uses two Redis keys per (key, action) pair:
 *
 *   ratelimit:{action}:{key}:count   — attempt counter, expires after window
 *   ratelimit:{action}:{key}:blocked — block flag, expires after block duration
 *
 * Both keys have Redis TTLs — no cleanup needed, no DB contention.
 *
 * Thread safety: Redis INCR is atomic. No race conditions.
 */
class RateLimiterService
{
    /**
     * Action config: [max_attempts, window_seconds, block_seconds]
     */
    private array $config = [
        'otp.request'         => [5,   600,  900],   // 5 in 10min, block 15min
        'login'               => [10,  900,  900],   // 10 in 15min, block 15min
        'topup.initiate'      => [20,  3600, 1800],  // 20 in 60min, block 30min
        'withdrawal.initiate' => [20,  3600, 1800],
        'transfer.initiate'   => [20,  3600, 1800],
        'kyc.submit'          => [5,   3600, 1800],
        'pin.change'          => [5,   3600, 1800],
    ];

    /**
     * Check and increment the attempt counter.
     * Aborts with 429 if blocked or limit exceeded.
     */
    public function attempt(string $key, string $action): void
    {
        if (! isset($this->config[$action])) {
            return;
        }

        [$maxAttempts, $windowSeconds, $blockSeconds] = $this->config[$action];

        $blockedKey = $this->blockedKey($action, $key);
        $countKey   = $this->countKey($action, $key);

        // Check if currently blocked
        if (Redis::exists($blockedKey)) {
            $ttl = Redis::ttl($blockedKey);
            abort(429, "Too many attempts. Try again in {$ttl} seconds.");
        }

        // Increment attempt counter atomically
        $attempts = Redis::incr($countKey);

        // First attempt — set the window TTL
        if ($attempts === 1) {
            Redis::expire($countKey, $windowSeconds);
        }

        // Exceeded limit — set block key and abort
        if ($attempts >= $maxAttempts) {
            Redis::setex($blockedKey, $blockSeconds, 1);
            Redis::del($countKey);
            abort(429, "Too many attempts. Try again in {$blockSeconds} seconds.");
        }
    }

    /**
     * Clear rate limit state for a key/action pair.
     * Called on successful login, OTP verification, etc.
     */
    public function clear(string $key, string $action): void
    {
        Redis::del($this->countKey($action, $key));
        Redis::del($this->blockedKey($action, $key));
    }

    /**
     * Return remaining attempts for a key/action pair.
     * Useful for including in API responses.
     */
    public function remaining(string $key, string $action): int
    {
        if (! isset($this->config[$action])) {
            return PHP_INT_MAX;
        }

        [$maxAttempts] = $this->config[$action];

        if (Redis::exists($this->blockedKey($action, $key))) {
            return 0;
        }

        $attempts = (int) Redis::get($this->countKey($action, $key));
        return max(0, $maxAttempts - $attempts);
    }

    /**
     * Return seconds until block expires, or 0 if not blocked.
     */
    public function blockedFor(string $key, string $action): int
    {
        $ttl = Redis::ttl($this->blockedKey($action, $key));
        return max(0, $ttl);
    }

    // ── Key helpers ──────────────────────────────────────────────────────────

    private function countKey(string $action, string $key): string
    {
        return "ratelimit:{$action}:{$key}:count";
    }

    private function blockedKey(string $action, string $key): string
    {
        return "ratelimit:{$action}:{$key}:blocked";
    }
}
