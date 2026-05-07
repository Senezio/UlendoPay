<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_corridors', function (Blueprint $table) {
            $table->decimal('guarantee_percent', 8, 6)->default(0.005000)
                  ->after('fee_flat')
                  ->comment('Guarantee fund contribution as a decimal e.g. 0.005 = 0.5%');
        });

        Schema::table('rate_locks', function (Blueprint $table) {
            $table->decimal('guarantee_percent', 8, 6)->default(0.005000)
                  ->after('fee_flat')
                  ->comment('Guarantee percent locked at quote time');
        });
    }

    public function down(): void
    {
        Schema::table('partner_corridors', function (Blueprint $table) {
            $table->dropColumn('guarantee_percent');
        });

        Schema::table('rate_locks', function (Blueprint $table) {
            $table->dropColumn('guarantee_percent');
        });
    }
};
