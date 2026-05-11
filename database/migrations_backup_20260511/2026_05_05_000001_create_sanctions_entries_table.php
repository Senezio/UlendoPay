<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanctions_entries', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20)->comment('eu, un, ofac');
            $table->enum('entity_type', ['individual', 'entity']);
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->json('aliases')->comment('JSON array of normalized alias strings');
            $table->json('country_codes')->nullable()->comment('JSON array of ISO 3166-1 alpha-2 codes');
            $table->date('date_of_birth')->nullable();
            $table->string('list_reference')->nullable()->comment('Original list ID or reference number');
            $table->json('metadata')->nullable()->comment('Raw extra fields from source data');
            $table->boolean('active')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanctions_entries');
    }
};
