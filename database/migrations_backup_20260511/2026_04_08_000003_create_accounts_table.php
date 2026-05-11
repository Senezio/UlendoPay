<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ACCOUNTS = the internal accounting entity.
// Every wallet, escrow pool, fee bucket, and guarantee fund
// is just an account here. This is the backbone of double-entry.
return new class extends Migration {
    public function up(): void {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. 3281321404 (user wallet), ESCROW-MWK, FEE-MWK
            $table->enum('type', [
                'user_wallet',   // belongs to a user
                'escrow',        // funds held during transfer
                'fee',           // platform fee collection
                'guarantee',     // guarantee fund per corridor
                'system',        // internal system accounts
                'partner'        // partner settlement accounts
            ]);
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('owner_id')->nullable(); // user_id or partner_id
            $table->string('owner_type')->nullable();           // App\Models\User, App\Models\Partner
            $table->string('corridor')->nullable();             // e.g. MWK-ZAR (for guarantee accounts)
            $table->enum('normal_balance', ['debit','credit']); // accounting convention
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['type', 'currency_code']);
            $table->index(['owner_id', 'owner_type']);
            $table->index('corridor');
        });
    }
    public function down(): void { Schema::dropIfExists('accounts'); }
};
