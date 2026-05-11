<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanctions_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('list_reference')->nullable()->unique();
            $table->string('name');
            $table->string('normalized_name', 500);
            $table->json('aliases')->nullable();
            $table->json('country_codes')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('source');
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source', 'active']);
            $table->index('active');
            $table->index('normalized_name');
        });

        Schema::create('pep_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('list_reference')->nullable()->unique();
            $table->string('name');
            $table->string('normalized_name', 500);
            $table->json('aliases')->nullable();
            $table->string('country_code', 3)->nullable();
            $table->enum('risk_level', ['low', 'medium', 'high'])->default('medium');
            $table->string('position')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('source');
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['country_code', 'active']);
            $table->index('active');
            $table->index('normalized_name');
        });

        Schema::create('compliance_screens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('screen_type', ['sanctions', 'pep']);
            $table->string('input_name');
            $table->unsignedTinyInteger('match_score')->default(0);
            $table->foreignId('sanctions_entry_id')->nullable()->constrained('sanctions_entries')->nullOnDelete();
            $table->foreignId('pep_entry_id')->nullable()->constrained('pep_entries')->nullOnDelete();
            $table->enum('result', ['clear', 'flagged', 'blocked']);
            $table->string('action_taken')->nullable();
            $table->json('match_details')->nullable();
            $table->enum('triggered_by', ['registration', 'kyc_approval', 'daily_job', 'name_change']);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('screened_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'screen_type']);
            $table->index('result');
        });

        Schema::create('compliance_alerts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('compliance_screen_id')->unique()->constrained('compliance_screens')->cascadeOnDelete();
            $table->enum('alert_type', ['sanctions_match', 'pep_match']);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('high');
            $table->unsignedTinyInteger('match_score')->default(0);
            $table->string('matched_name')->nullable();
            $table->enum('status', ['new', 'reviewing', 'cleared', 'confirmed'])->default('new');
            $table->enum('triggered_by', ['registration', 'kyc_approval', 'daily_job', 'name_change']);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_alerts');
        Schema::dropIfExists('compliance_screens');
        Schema::dropIfExists('pep_entries');
        Schema::dropIfExists('sanctions_entries');
    }
};
