<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// WALLET = user-facing abstraction.
// It points to an account. The balance shown to users
// is derived from journal_entries on that account.
// Never write balances here directly — read from journal_entries.
return new class extends Migration {
    public function up(): void {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->foreignId('account_id')->constrained()->onDelete('restrict');
            $table->string('currency_code', 3);
            $table->enum('status', ['active','frozen','closed'])->default('active');
            $table->timestamps();
            $table->unique(['user_id', 'currency_code']);
            $table->index('account_id');
        });
    }
    public function down(): void { Schema::dropIfExists('wallets'); }
};
