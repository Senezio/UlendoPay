<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Widen name and normalized_name to text - sanctions list entries can exceed 255 chars
        DB::statement('ALTER TABLE sanctions_entries MODIFY name TEXT NOT NULL');
        DB::statement('ALTER TABLE sanctions_entries MODIFY normalized_name TEXT NOT NULL');
        DB::statement('ALTER TABLE pep_entries MODIFY name TEXT NOT NULL');
        DB::statement('ALTER TABLE pep_entries MODIFY normalized_name TEXT NOT NULL');

        // Drop the default string indexes - they break on TEXT columns in MySQL
        Schema::table('sanctions_entries', function (Blueprint $table) {
            $table->dropIndex('sanctions_entries_normalized_name_index');
        });

        Schema::table('pep_entries', function (Blueprint $table) {
            $table->dropIndex('pep_entries_normalized_name_index');
        });

        // Re-add indexes with a prefix length safe for MySQL TEXT columns
        DB::statement('ALTER TABLE sanctions_entries ADD INDEX sanctions_entries_normalized_name_index (normalized_name(191))');
        DB::statement('ALTER TABLE pep_entries ADD INDEX pep_entries_normalized_name_index (normalized_name(191))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sanctions_entries MODIFY name VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE sanctions_entries MODIFY normalized_name VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE pep_entries MODIFY name VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE pep_entries MODIFY normalized_name VARCHAR(255) NOT NULL');

        Schema::table('sanctions_entries', function (Blueprint $table) {
            $table->dropIndex('sanctions_entries_normalized_name_index');
        });

        Schema::table('pep_entries', function (Blueprint $table) {
            $table->dropIndex('pep_entries_normalized_name_index');
        });

        DB::statement('ALTER TABLE sanctions_entries ADD INDEX sanctions_entries_normalized_name_index (normalized_name)');
        DB::statement('ALTER TABLE pep_entries ADD INDEX pep_entries_normalized_name_index (normalized_name)');
    }
};
