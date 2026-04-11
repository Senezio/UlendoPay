<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Daily reconciliation snapshots.
// A scheduled job computes expected vs actual balance per account.
// Any mismatch is flagged here for investigation.
// This is how you detect silent corruption before it compounds.
return new class extends Migration {
    public function up(): void {
        Schema::create('reconciliation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('restrict');
            $table->date('snapshot_date');
            $table->decimal('computed_balance', 20, 6);   // sum from journal_entries
            $table->decimal('expected_balance', 20, 6);   // from previous snapshot + movements
            $table->decimal('variance', 20, 6)->default(0); // computed - expected
            $table->enum('status', ['matched','mismatch','under_review','resolved'])->default('matched');
            $table->text('notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['account_id', 'snapshot_date']);
            $table->index(['status', 'snapshot_date']);
            $table->index('snapshot_date');
        });
    }
    public function down(): void { Schema::dropIfExists('reconciliation_snapshots'); }
};
