<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_records', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('document_number');
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('date_of_birth');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('country_code');
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_records', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'gender']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'gender']);
        });
    }
};
