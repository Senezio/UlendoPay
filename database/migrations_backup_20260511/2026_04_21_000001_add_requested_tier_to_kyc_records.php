<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('kyc_records', function (Blueprint $table) {
            $table->string('requested_tier')->nullable()->after('status');
        });
    }
    public function down(): void {
        Schema::table('kyc_records', function (Blueprint $table) {
            $table->dropColumn('requested_tier');
        });
    }
};
