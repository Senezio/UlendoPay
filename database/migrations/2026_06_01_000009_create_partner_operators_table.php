<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_operators', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('country', 3);
            $table->string('currency', 10);
            $table->string('correspondent', 50);
            $table->enum('operation_type', ['DEPOSIT', 'PAYOUT', 'REFUND']);
            $table->decimal('min_amount', 20, 6)->default(0);
            $table->decimal('max_amount', 20, 6)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'correspondent', 'operation_type'], 'partner_op_unique');
            $table->index(['currency', 'operation_type', 'is_active']);
            $table->index(['partner_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_operators');
    }
};
