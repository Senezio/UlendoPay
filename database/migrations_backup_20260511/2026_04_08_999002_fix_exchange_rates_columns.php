<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('exchange_rates', function (Blueprint $table) {
            if (!Schema::hasColumn('exchange_rates', 'buying_rate')) {
                $table->decimal('buying_rate', 20, 8)->nullable()->after('rate');
            }
            if (!Schema::hasColumn('exchange_rates', 'middle_rate')) {
                $table->decimal('middle_rate', 20, 8)->nullable()->after('buying_rate');
            }
            if (!Schema::hasColumn('exchange_rates', 'selling_rate')) {
                $table->decimal('selling_rate', 20, 8)->nullable()->after('middle_rate');
            }
            if (!Schema::hasColumn('exchange_rates', 'is_stale')) {
                $table->boolean('is_stale')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('exchange_rates', 'stale_reason')) {
                $table->string('stale_reason')->nullable()->after('is_stale');
            }
        });
    }
    public function down(): void {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropColumn([
                'buying_rate','middle_rate','selling_rate',
                'is_stale','stale_reason'
            ]);
        });
    }
};
