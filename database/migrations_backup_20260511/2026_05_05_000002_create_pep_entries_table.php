<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pep_entries', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20)->comment('e.g. opensanctions, local');
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->json('aliases')->comment('JSON array of normalized alias strings');
            $table->char('country_code', 2)->nullable()->comment('ISO 3166-1 alpha-2');
            $table->string('position')->nullable()->comment('e.g. Minister of Finance');
            $table->enum('risk_level', ['low', 'medium', 'high'])->default('medium');
            $table->date('date_of_birth')->nullable();
            $table->json('metadata')->nullable()->comment('Raw extra fields from source data');
            $table->boolean('active')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['country_code', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pep_entries');
    }
};
