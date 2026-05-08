<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('bank_name');
            $table->string('bank_code')->nullable();
            $table->text('account_number_encrypted');
            $table->string('account_number_masked', 20);
            $table->string('account_name');
            $table->string('branch_code')->nullable();
            $table->string('currency_code', 3);
            $table->string('country_code', 3);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_bank_accounts');
    }
};
