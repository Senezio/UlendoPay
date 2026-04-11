<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('top_ups', function (Blueprint $table) {
            $table->id();

            // Reference visible to user
            $table->string('reference')->unique();

            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->foreignId('wallet_id')->constrained()->onDelete('restrict');

            // Amount details
            $table->decimal('amount', 20, 6);
            $table->string('currency_code', 3);

            // Mobile money details
            $table->string('phone_number', 20);
            $table->string('mobile_operator');
            $table->string('country_code', 3);

            // Pawapay details
            $table->string('pawapay_deposit_id')->nullable()->unique();
            $table->string('correspondent')->nullable();
            $table->json('pawapay_request_payload')->nullable();
            $table->json('pawapay_response_payload')->nullable();
            $table->json('pawapay_webhook_payload')->nullable();

            // Status lifecycle
            // initiated → pending → completed → failed → refunded
            $table->enum('status', [
                'initiated',
                'pending',
                'completed',
                'failed',
                'cancelled',
            ])->default('initiated');

            $table->text('failure_reason')->nullable();

            // Timestamps
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('pawapay_deposit_id');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('top_ups');
    }
};
