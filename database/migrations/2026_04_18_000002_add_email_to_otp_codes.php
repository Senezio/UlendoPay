<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('delivery_phone', 20)->nullable()->change();
            $table->string('delivery_email')->nullable()->after('delivery_phone');
        });
    }

    public function down(): void {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('delivery_phone', 20)->nullable(false)->change();
            $table->dropColumn('delivery_email');
        });
    }
};
