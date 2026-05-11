<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->decimal('rate', 20, 8);
            $table->decimal('inverse_rate', 20, 8);
            $table->decimal('margin_percent', 8, 4)->default(0);
            $table->string('source')->default('manual'); // manual, api, partner
            $table->boolean('is_active')->default(true);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['from_currency', 'to_currency', 'fetched_at']);
            $table->index(['from_currency', 'to_currency', 'is_active']);
            $table->index('expires_at');
        });
    }
    public function down(): void { Schema::dropIfExists('exchange_rates'); }
};
