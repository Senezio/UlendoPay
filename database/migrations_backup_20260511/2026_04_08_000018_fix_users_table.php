<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            // Make email nullable — phone is primary identifier
            $table->string('email')->nullable()->change();

            // Phone encrypted at rest, hash for fast lookups
            $table->text('phone_encrypted')->nullable()->after('email');
            $table->string('phone_hash', 64)->nullable()->unique()->after('phone_encrypted');

            // Drop old plain phone if it exists
            if (Schema::hasColumn('users', 'phone')) {
                $table->dropColumn('phone');
            }
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_encrypted', 'phone_hash']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
