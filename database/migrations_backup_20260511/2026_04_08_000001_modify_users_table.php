<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('country_code', 3)->nullable()->after('phone');
            $table->enum('kyc_status', ['none','pending','verified','rejected'])->default('none')->after('country_code');
            $table->enum('status', ['active','suspended','closed'])->default('active')->after('kyc_status');
            $table->timestamp('kyc_verified_at')->nullable()->after('status');
            $table->index('kyc_status');
            $table->index('status');
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone','country_code','kyc_status','status','kyc_verified_at']);
        });
    }
};
