<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sanctions_entries', function (Blueprint $table) {
            $table->unique('list_reference');
        });

        Schema::table('pep_entries', function (Blueprint $table) {
            $table->unique('list_reference');
        });
    }

    public function down(): void
    {
        Schema::table('sanctions_entries', function (Blueprint $table) {
            $table->dropUnique(['list_reference']);
        });

        Schema::table('pep_entries', function (Blueprint $table) {
            $table->dropUnique(['list_reference']);
        });
    }
};
