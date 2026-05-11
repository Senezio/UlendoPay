<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('two_factor_auth', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('restrict');
            // Secret encrypted — never stored plaintext
            $table->text('secret_encrypted');
            $table->text('recovery_codes_encrypted');
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_enabled']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('two_factor_auth');
    }
};
