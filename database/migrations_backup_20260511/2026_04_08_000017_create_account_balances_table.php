<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ACCOUNT BALANCES = running balance per account.
// Never computed on the fly from journal_entries at query time.
// Updated atomically inside the same DB transaction as journal inserts.
// Rule: if journal insert succeeds but balance update fails → entire DB transaction rolls back.
// This means: balance here always reflects journal_entries exactly.
return new class extends Migration {
    public function up(): void {
        Schema::create('account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->onDelete('restrict');
            $table->decimal('balance', 20, 6)->default(0);
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('last_journal_entry_id')->nullable(); // for audit trail
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('account_id');
        });
    }
    public function down(): void { Schema::dropIfExists('account_balances'); }
};
