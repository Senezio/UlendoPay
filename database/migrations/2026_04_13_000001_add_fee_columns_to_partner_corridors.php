<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('partner_corridors', function (Blueprint $table) {
            $table->decimal('fee_percent', 8, 4)->default(1.5)->after('priority');
            $table->decimal('fee_flat', 20, 6)->default(0)->after('fee_percent');
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->decimal('success_rate', 5, 2)->default(100)->after('retry_delay_seconds');
            $table->integer('avg_response_time_ms')->default(0)->after('success_rate');
        });
    }

    public function down(): void {
        Schema::table('partner_corridors', function (Blueprint $table) {
            $table->dropColumn(['fee_percent', 'fee_flat']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['success_rate', 'avg_response_time_ms']);
        });
    }
};
