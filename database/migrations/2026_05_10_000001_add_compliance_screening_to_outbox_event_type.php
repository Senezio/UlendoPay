<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE outbox_events MODIFY COLUMN event_type ENUM(
            'disbursement_requested',
            'refund_requested',
            'sms_notification',
            'rate_fetch_requested',
            'reconciliation_triggered',
            'internal_settlement',
            'compliance_screening'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE outbox_events MODIFY COLUMN event_type ENUM(
            'disbursement_requested',
            'refund_requested',
            'sms_notification',
            'rate_fetch_requested',
            'reconciliation_triggered',
            'internal_settlement'
        ) NOT NULL");
    }
};
