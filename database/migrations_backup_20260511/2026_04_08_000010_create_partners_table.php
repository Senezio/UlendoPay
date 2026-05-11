<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['mobile_money','bank','cash_pickup']);
            $table->string('country_code', 3);
            $table->json('api_config')->nullable();   // encrypted at app layer
            $table->integer('timeout_seconds')->default(30);
            $table->integer('max_retries')->default(3);
            $table->integer('retry_delay_seconds')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('partner_corridors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->onDelete('restrict');
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->decimal('min_amount', 20, 6);
            $table->decimal('max_amount', 20, 6);
            $table->integer('priority')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['partner_id', 'from_currency', 'to_currency']);
            $table->index(['from_currency', 'to_currency', 'is_active']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('partner_corridors');
        Schema::dropIfExists('partners');
    }
};
