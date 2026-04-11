<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            $table->foreignId('sender_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('recipient_id')->constrained('recipients')->onDelete('restrict');
            $table->foreignId('rate_lock_id')->constrained('rate_locks')->onDelete('restrict');
            $table->foreignId('partner_id')->nullable()->constrained('partners')->onDelete('restrict');
            // Accounting links — ties transaction to its journal entry group
            $table->foreignId('journal_entry_group_id')->nullable()->constrained('journal_entry_groups')->onDelete('restrict');
            $table->decimal('send_amount', 20, 6);
            $table->string('send_currency', 3);
            $table->decimal('receive_amount', 20, 6);
            $table->string('receive_currency', 3);
            $table->decimal('locked_rate', 20, 8);
            $table->decimal('fee_amount', 20, 6);
            $table->decimal('guarantee_contribution', 20, 6)->default(0);
            $table->string('partner_reference')->nullable();
            $table->enum('status', [
                'initiated',
                'escrowed',
                'processing',
                'retrying',
                'completed',
                'failed',
                'refund_pending',
                'refunded',
                'disputed'
            ])->default('initiated');
            $table->string('failure_reason')->nullable();
            $table->integer('disbursement_attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('escrowed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->index(['sender_id', 'status']);
            $table->index(['status', 'next_attempt_at']);
            $table->index('reference_number');
            $table->index('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('transactions'); }
};
