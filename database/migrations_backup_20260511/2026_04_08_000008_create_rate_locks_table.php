<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('rate_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->foreignId('exchange_rate_id')->constrained()->onDelete('restrict');
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->decimal('locked_rate', 20, 8);
            $table->decimal('fee_percent', 8, 4);
            $table->decimal('fee_flat', 20, 6)->default(0);
            $table->enum('status', ['active','used','expired'])->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });
    }
    public function down(): void { Schema::dropIfExists('rate_locks'); }
};
