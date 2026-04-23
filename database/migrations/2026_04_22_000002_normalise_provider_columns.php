<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rename PawaPay-specific columns to provider-neutral names.
     * Add a `provider` enum so every record knows which partner handled it.
     *
     * top_ups:
     *   pawapay_deposit_id       → provider_reference
     *   pawapay_request_payload  → provider_request_payload
     *   pawapay_response_payload → provider_response_payload
     *   pawapay_webhook_payload  → provider_webhook_payload
     *   + provider enum('pawapay','mtnmomo')
     *
     * withdrawals: same pattern with pawapay_payout_id → provider_reference
     */
    public function up(): void
    {
        // ── top_ups ──────────────────────────────────────────────────────────
        Schema::table('top_ups', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('country_code');
            $table->string('provider_reference')->nullable()->after('provider');
            $table->json('provider_request_payload')->nullable()->after('correspondent');
            $table->json('provider_response_payload')->nullable()->after('provider_request_payload');
            $table->json('provider_webhook_payload')->nullable()->after('provider_response_payload');
        });

        // Backfill existing records — all existing top_ups were PawaPay
        DB::statement("
            UPDATE top_ups SET
                provider           = 'pawapay',
                provider_reference = pawapay_deposit_id,
                provider_request_payload  = pawapay_request_payload,
                provider_response_payload = pawapay_response_payload,
                provider_webhook_payload  = pawapay_webhook_payload
        ");

        Schema::table('top_ups', function (Blueprint $table) {
            $table->dropIndex(['pawapay_deposit_id']);
            $table->dropColumn([
                'pawapay_deposit_id',
                'pawapay_request_payload',
                'pawapay_response_payload',
                'pawapay_webhook_payload',
            ]);
            // Unique index on provider_reference
            $table->unique('provider_reference');
            $table->index('provider');
        });

        // ── withdrawals ───────────────────────────────────────────────────────
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('country_code');
            $table->string('provider_reference')->nullable()->after('provider');
            $table->json('provider_request_payload')->nullable()->after('correspondent');
            $table->json('provider_response_payload')->nullable()->after('provider_request_payload');
            $table->json('provider_webhook_payload')->nullable()->after('provider_response_payload');
        });

        // Backfill — no withdrawal records exist yet, but safe to run
        DB::statement("
            UPDATE withdrawals SET
                provider           = 'pawapay',
                provider_reference = pawapay_payout_id,
                provider_request_payload  = pawapay_request_payload,
                provider_response_payload = pawapay_response_payload,
                provider_webhook_payload  = pawapay_webhook_payload
            WHERE pawapay_payout_id IS NOT NULL
        ");

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropIndex(['pawapay_payout_id']);
            $table->dropColumn([
                'pawapay_payout_id',
                'pawapay_request_payload',
                'pawapay_response_payload',
                'pawapay_webhook_payload',
            ]);
            $table->unique('provider_reference');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        // ── top_ups ──────────────────────────────────────────────────────────
        Schema::table('top_ups', function (Blueprint $table) {
            $table->dropUnique(['provider_reference']);
            $table->dropIndex(['provider']);
            $table->string('pawapay_deposit_id')->nullable()->unique();
            $table->json('pawapay_request_payload')->nullable();
            $table->json('pawapay_response_payload')->nullable();
            $table->json('pawapay_webhook_payload')->nullable();
        });

        DB::statement("
            UPDATE top_ups SET
                pawapay_deposit_id       = provider_reference,
                pawapay_request_payload  = provider_request_payload,
                pawapay_response_payload = provider_response_payload,
                pawapay_webhook_payload  = provider_webhook_payload
            WHERE provider = 'pawapay'
        ");

        Schema::table('top_ups', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'provider_reference',
                'provider_request_payload',
                'provider_response_payload',
                'provider_webhook_payload',
            ]);
        });

        // ── withdrawals ───────────────────────────────────────────────────────
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropUnique(['provider_reference']);
            $table->dropIndex(['provider']);
            $table->string('pawapay_payout_id')->nullable()->unique();
            $table->json('pawapay_request_payload')->nullable();
            $table->json('pawapay_response_payload')->nullable();
            $table->json('pawapay_webhook_payload')->nullable();
        });

        DB::statement("
            UPDATE withdrawals SET
                pawapay_payout_id        = provider_reference,
                pawapay_request_payload  = provider_request_payload,
                pawapay_response_payload = provider_response_payload,
                pawapay_webhook_payload  = provider_webhook_payload
            WHERE provider = 'pawapay'
        ");

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'provider_reference',
                'provider_request_payload',
                'provider_response_payload',
                'provider_webhook_payload',
            ]);
        });
    }
};
