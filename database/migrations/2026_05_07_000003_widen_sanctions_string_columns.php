<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sanctions_entries', function (Blueprint $table) {
            $table->text('list_reference')->nullable()->change();
            $table->text('name')->change();
            $table->text('normalized_name')->change();
        });

        Schema::table('pep_entries', function (Blueprint $table) {
            $table->text('list_reference')->nullable()->change();
            $table->text('name')->change();
            $table->text('normalized_name')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sanctions_entries', function (Blueprint $table) {
            $table->string('list_reference')->nullable()->change();
            $table->string('name')->change();
            $table->string('normalized_name')->change();
        });

        Schema::table('pep_entries', function (Blueprint $table) {
            $table->string('list_reference')->nullable()->change();
            $table->string('name')->change();
            $table->string('normalized_name')->change();
        });
    }
};
