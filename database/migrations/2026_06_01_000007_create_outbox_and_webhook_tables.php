<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('event_type', [
                'disbursement_requested', 'refund_requested', 'sms_notification',
                'rate_fetch_requested', 'reconciliation_triggered',
                'internal_settlement', 'compliance_screening'
            ]);
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->json('payload');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(4);
            $table->text('failure_reason')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['status', 'next_attempt_at']);
            $table->index('event_type');
            $table->index('transaction_id');
        });

        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source');
            $table->string('direction');
            $table->string('provider_reference')->nullable();
            $table->string('status')->nullable();
            $table->string('outcome');
            $table->boolean('signature_valid')->default(true);
            $table->json('payload')->nullable();
            $table->json('headers')->nullable();
            $table->text('error')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('received_at')->useCurrent();

            $table->index(['source', 'received_at']);
            $table->index('provider_reference');
            $table->index(['outcome', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('outbox_events');
    }
};
