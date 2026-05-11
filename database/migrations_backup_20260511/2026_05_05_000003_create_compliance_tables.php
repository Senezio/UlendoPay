<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_screens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('screen_type', ['sanctions', 'pep']);
            $table->string('input_name')->comment('The name string that was screened');
            $table->unsignedTinyInteger('match_score')->default(0)->comment('0-100');
            // Split nullable FKs instead of polymorphic matched_entry_type
            $table->foreignId('sanctions_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pep_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('result', ['clear', 'flagged', 'blocked']);
            $table->string('action_taken')->nullable()->comment('e.g. wallet_frozen, flagged_for_review');
            $table->json('match_details')->nullable()->comment('Algorithm, scores, alias_match, country_match etc.');
            $table->enum('triggered_by', ['registration', 'kyc_approval', 'daily_job', 'name_change']);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('screened_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'screen_type']);
            $table->index(['result', 'screen_type']);
            $table->index('triggered_by');
        });

        Schema::create('compliance_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('compliance_screen_id')->constrained()->cascadeOnDelete();
            $table->enum('alert_type', ['sanctions_match', 'pep_match']);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('high');
            $table->unsignedTinyInteger('match_score')->default(0);
            $table->string('matched_name')->nullable()->comment('The name from the list that triggered the alert');
            $table->enum('status', ['new', 'reviewing', 'cleared', 'confirmed'])->default('new');
            $table->enum('triggered_by', ['registration', 'kyc_approval', 'daily_job', 'name_change']);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['alert_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_alerts');
        Schema::dropIfExists('compliance_screens');
    }
};
