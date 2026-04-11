<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('exchange_rates', function (Blueprint $table) {
            // Restore full RBM rate data
            $table->decimal('buying_rate', 20, 8)->nullable()->after('rate');
            $table->decimal('middle_rate', 20, 8)->nullable()->after('buying_rate');
            $table->decimal('selling_rate', 20, 8)->nullable()->after('middle_rate');

            // Rate expiry rule:
            // Expires 26 hours after fetched_at as safety buffer
            // Gives RBM time to publish next day's rates
            // If next fetch succeeds before expiry, new record supersedes
        });
    }
    public function down(): void {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropColumn(['buying_rate', 'middle_rate', 'selling_rate']);
        });
    }
};
