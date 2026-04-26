<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE kyc_records MODIFY COLUMN document_type ENUM('passport','national_id','drivers_license','utility_bill','bank_statement') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE kyc_records MODIFY COLUMN document_type ENUM('passport','national_id','drivers_license','utility_bill') NOT NULL");
    }
};
