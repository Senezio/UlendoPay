<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pep_entries', function (Blueprint $table) {
            $table->string('list_reference')->nullable()->unique()->after('source')
                  ->comment('OpenSanctions ID - unique identifier from source');
        });
    }

    public function down(): void
    {
        Schema::table('pep_entries', function (Blueprint $table) {
            $table->dropUnique(['list_reference']);
            $table->dropColumn('list_reference');
        });
    }
};
