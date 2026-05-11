<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('partners', function (Blueprint $table) {
            // Replace plain json api_config with encrypted version
            if (Schema::hasColumn('partners', 'api_config')) {
                $table->dropColumn('api_config');
            }
            $table->text('api_config_encrypted')->nullable()->after('country_code');
            $table->softDeletes();
        });
    }
    public function down(): void {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['api_config_encrypted', 'deleted_at']);
            $table->json('api_config')->nullable();
        });
    }
};
