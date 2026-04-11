<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->enum('event_type', [
                'disbursement_requested',
                'refund_requested',
                'sms_notification',
                'rate_fetch_requested',
                'reconciliation_triggered'
            ]);
            $table->foreignId('transaction_id')->nullable()->constrained()->onDelete('restrict');
            $table->json('payload');
            $table->enum('status', ['pending','processing','completed','failed'])->default('pending');
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(4);
            $table->string('failure_reason')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['status', 'next_attempt_at']);
            $table->index('event_type');
            $table->index('transaction_id');
        });
    }
    public function down(): void { Schema::dropIfExists('outbox_events'); }
};
