<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;

// ── Exchange Rate Fetch ───────────────────────────────────────────────────────
// Runs daily at 07:00 — RBM publishes rates early morning Malawi time
// If fetch fails, stale rates are flagged and new transactions are blocked
Schedule::command('rates:fetch')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('[scheduler] rates:fetch completed successfully');
    })
    ->onFailure(function () {
        Log::error('[scheduler] rates:fetch FAILED — corridors may be stale');
    });

// ── Outbox Worker ────────────────────────────────────────────────────────────
// Runs every minute — picks up pending disbursements, refunds, SMS notifications
// limit=50 prevents overwhelming the server on backlog
// withoutOverlapping(5) — locks for max 5 minutes to prevent concurrent runs
Schedule::command('outbox:process --limit=50')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onFailure(function () {
        Log::error('[scheduler] outbox:process failed');
    });

// ── Expire stale rate locks ──────────────────────────────────────────────────
// Runs every 5 minutes — marks expired rate locks so they cannot be used
Schedule::command('rate-locks:expire')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[scheduler] rate-locks:expire failed');
    });

// ── Reconciliation ───────────────────────────────────────────────────────────
// Runs daily at 02:00 — snapshots all account balances and flags mismatches
Schedule::command('reconcile:accounts')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[scheduler] reconcile:accounts FAILED');
    });

// ── Prune expired idempotency keys ───────────────────────────────────────────
// Runs daily at 03:00 — removes expired keys to keep table lean
Schedule::command('idempotency:prune')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[scheduler] idempotency:prune FAILED');
    });

// ── Expire unclaimed pending transfers ───────────────────────────────────────
// Runs every hour — refunds transfers not claimed within 48 hours
Schedule::command('claims:expire')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('[scheduler] claims:expire FAILED');
    });
