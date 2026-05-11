<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add staff columns to users table
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_staff')->default(false)->after('status');
            $table->enum('role', [
                'super_admin',
                'kyc_reviewer',
                'finance_officer',
                'support_agent',
            ])->nullable()->after('is_staff');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_staff', 'role']);
        });
    }
};
