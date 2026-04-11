<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// JOURNAL ENTRIES = single source of truth for ALL money movement.
// Every credit card, wallet top-up, fee, escrow, disbursement —
// everything flows through here as debit/credit pairs.
// wallet_transactions is GONE. This replaces it entirely.
return new class extends Migration {
    public function up(): void {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('journal_entry_groups')->onDelete('restrict');
            $table->foreignId('account_id')->constrained('accounts')->onDelete('restrict');
            $table->enum('entry_type', ['debit','credit']);
            $table->decimal('amount', 20, 6);
            $table->string('currency_code', 3);
            $table->string('description')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            // Immutable — no updated_at
            $table->index(['account_id', 'posted_at']);
            $table->index(['group_id', 'entry_type']);
            $table->index('posted_at');
        });
    }
    public function down(): void { Schema::dropIfExists('journal_entries'); }
};
