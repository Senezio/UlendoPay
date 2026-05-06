<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::table('transfer_tiers', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->default(0)->after('name');
            $table->index('level');
        });

        // Set levels based on name
        DB::table('transfer_tiers')->where('name', 'unverified')->update(['level' => 0]);
        DB::table('transfer_tiers')->where('name', 'basic')->update(['level' => 1]);
        DB::table('transfer_tiers')->where('name', 'verified')->update(['level' => 2]);
    }

    public function down(): void {
        Schema::table('transfer_tiers', function (Blueprint $table) {
            $table->dropIndex(['level']);
            $table->dropColumn('level');
        });
    }
};
