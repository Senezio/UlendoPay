<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table("withdrawals", function (Blueprint $table) {
            // Make mobile money fields nullable for bank withdrawals
            $table->string("phone_number", 20)->nullable()->change();
            $table->string("mobile_operator")->nullable()->change();

            // Bank transfer fields
            $table->string("bank_account_number")->nullable()->after("correspondent");
            $table->string("bank_branch_code")->nullable()->after("bank_account_number");
            $table->string("bank_name")->nullable()->after("bank_branch_code");

            $table->index(["withdrawal_method", "status"]);
        });
    }

    public function down(): void
    {
        Schema::table("withdrawals", function (Blueprint $table) {
            $table->string("phone_number", 20)->nullable(false)->change();
            $table->string("mobile_operator")->nullable(false)->change();
            $table->dropColumn(["bank_account_number", "bank_branch_code", "bank_name"]);
            $table->dropIndex(["withdrawal_method", "status"]);
        });
    }
};
