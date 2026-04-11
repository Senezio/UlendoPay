<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('recipients', function (Blueprint $table) {
            // Encrypt PII — store as text (AES encrypted values exceed varchar)
            $table->text('full_name_encrypted')->nullable()->after('full_name');
            $table->text('phone_encrypted')->nullable()->after('full_name_encrypted');
            $table->string('phone_hash', 64)->nullable()->after('phone_encrypted');
            $table->text('bank_account_encrypted')->nullable()->after('bank_account_number');

            // Soft deletes for GDPR right to be forgotten
            $table->softDeletes();
        });
    }
    public function down(): void {
        Schema::table('recipients', function (Blueprint $table) {
            $table->dropColumn([
                'full_name_encrypted',
                'phone_encrypted',
                'phone_hash',
                'bank_account_encrypted',
                'deleted_at'
            ]);
        });
    }
};
