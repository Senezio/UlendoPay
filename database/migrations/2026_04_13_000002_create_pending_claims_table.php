<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pending_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->onDelete('restrict');
            $table->string('recipient_phone_hash');
            $table->string('recipient_phone_masked');
            $table->decimal('amount', 20, 6);
            $table->string('currency_code', 3);
            $table->enum('status', ['pending','claimed','expired','refunded'])->default('pending');
            $table->foreignId('claimed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_phone_hash', 'status']);
            $table->index(['status', 'expires_at']);
        });

        // Add pending_claim status to transactions
        DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM(
            'initiated','escrowed','processing','retrying','completed',
            'failed','refund_pending','refunded','disputed','pending_claim'
        ) DEFAULT 'initiated'");
    }

    public function down(): void {
        Schema::dropIfExists('pending_claims');
        DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM(
            'initiated','escrowed','processing','retrying','completed',
            'failed','refund_pending','refunded','disputed'
        ) DEFAULT 'initiated'");
    }
};
