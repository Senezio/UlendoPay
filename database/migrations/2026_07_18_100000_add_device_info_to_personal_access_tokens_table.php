<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('device_name')->nullable()->after('name');
            $table->string('platform', 20)->nullable()->after('device_name');
            $table->string('user_agent', 512)->nullable()->after('platform');
            $table->string('ip_address', 45)->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['device_name', 'platform', 'user_agent', 'ip_address']);
        });
    }
};
