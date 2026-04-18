<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('flagged_for_review')->default(false)->after('status');
            $table->integer('risk_score')->default(0)->after('flagged_for_review');
            $table->json('fraud_context')->nullable()->after('risk_score');
        });
    }

    public function down(): void {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['flagged_for_review', 'risk_score', 'fraud_context']);
        });
    }
};
