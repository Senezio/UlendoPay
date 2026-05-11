<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Step 1 — Add wallet_account column
        Schema::table('recipients', function (Blueprint $table) {
            $table->string('wallet_account', 20)->nullable()->after('bank_branch_code');
        });

        // Step 2 — Modify enum to include wallet_transfer
        DB::statement("ALTER TABLE recipients MODIFY COLUMN payment_method ENUM('mobile_money','bank_transfer','cash_pickup','wallet_transfer') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE recipients MODIFY COLUMN payment_method ENUM('mobile_money','bank_transfer','cash_pickup') NOT NULL");

        Schema::table('recipients', function (Blueprint $table) {
            $table->dropColumn('wallet_account');
        });
    }
};
