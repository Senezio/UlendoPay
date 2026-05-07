<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting Periods
 *
 * Each row represents a financial reporting period (typically one calendar month).
 * Periods progress through three states:
 *
 *   open    → actively accepting journal entries
 *   closed  → no new entries; reports can be generated and reviewed
 *   locked  → immutable; financial statements are frozen for audit
 *
 * Once locked, NO journal entry may be posted with a posted_at date
 * falling within this period. Corrections must be posted in the next
 * open period as adjusting entries.
 *
 * Snapshots of all four financial statements are stored as JSON
 * at close time and again at lock time for audit trail.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();

            $table->string('name');                          // e.g. "May 2026"
            $table->date('starts_on');                       // inclusive
            $table->date('ends_on');                         // inclusive
            $table->enum('status', ['open', 'closed', 'locked'])->default('open');

            // Who performed each state transition
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('locked_at')->nullable();

            // Financial statement snapshots captured at close and lock time
            // Stored as JSON — immutable audit record of the books at period end
            $table->json('trial_balance_snapshot')->nullable();
            $table->json('balance_sheet_snapshot')->nullable();
            $table->json('profit_loss_snapshot')->nullable();
            $table->json('cash_flow_snapshot')->nullable();

            // Locked snapshots — taken again at lock time after any close adjustments
            $table->json('locked_trial_balance')->nullable();
            $table->json('locked_balance_sheet')->nullable();
            $table->json('locked_profit_loss')->nullable();
            $table->json('locked_cash_flow')->nullable();

            // Summary figures for quick dashboard display (denormalised from snapshots)
            $table->decimal('total_assets', 20, 6)->nullable();
            $table->decimal('total_liabilities', 20, 6)->nullable();
            $table->decimal('total_equity', 20, 6)->nullable();
            $table->decimal('net_profit', 20, 6)->nullable();
            $table->decimal('net_cash_change', 20, 6)->nullable();

            $table->string('notes')->nullable();             // auditor notes

            $table->timestamps();

            // Enforce no overlapping periods at DB level
            $table->unique('starts_on');
            $table->unique('ends_on');
            $table->index('status');
            $table->index(['starts_on', 'ends_on']);

            $table->foreign('opened_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('closed_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('locked_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
