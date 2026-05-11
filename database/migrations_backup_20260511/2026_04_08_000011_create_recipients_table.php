<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('country_code', 3);
            $table->enum('payment_method', ['mobile_money','bank_transfer','cash_pickup']);
            $table->string('mobile_network')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_branch_code')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
        });
    }
    public function down(): void { Schema::dropIfExists('recipients'); }
};
