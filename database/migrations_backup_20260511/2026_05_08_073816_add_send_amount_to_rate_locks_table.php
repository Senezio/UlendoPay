<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table("rate_locks", function (Blueprint $table) {
            $table->decimal("send_amount", 20, 6)->nullable()->after("guarantee_percent");
        });
    }

    public function down(): void
    {
        Schema::table("rate_locks", function (Blueprint $table) {
            $table->dropColumn("send_amount");
        });
    }
};
