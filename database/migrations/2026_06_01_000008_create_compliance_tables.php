<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanctions_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source', 20);
            $table->enum('entity_type', ['individual', 'entity']);
            $table->text('name');
            $table->text('normalized_name');
            $table->json('aliases');
            $table->json('country_codes')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('list_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'active']);
            $table->index('active');
        });

        DB::statement('CREATE INDEX sanctions_entries_normalized_name_index ON sanctions_entries (normalized_name(191))');
        DB::statement('CREATE UNIQUE INDEX sanctions_entries_list_reference_unique ON sanctions_entries (list_reference(191))');

        Schema::create('pep_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source', 20);
            $table->text('list_reference')->nullable();
            $table->text('name');
            $table->text('normalized_name');
            $table->json('aliases');
            $table->char('country_code', 2)->nullable();
            $table->string('position')->nullable();
            $table->enum('risk_level', ['low', 'medium', 'high'])->default('medium');
            $table->date('date_of_birth')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['country_code', 'active']);
            $table->index('active');
        });

        DB::statement('CREATE INDEX pep_entries_normalized_name_index ON pep_entries (normalized_name(191))');
        DB::statement('CREATE UNIQUE INDEX pep_entries_list_reference_unique ON pep_entries (list_reference(191))');

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
            $table->index(['result', 'screen_type']);
            $table->index('triggered_by');
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
            $table->index(['alert_type', 'status']);
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
