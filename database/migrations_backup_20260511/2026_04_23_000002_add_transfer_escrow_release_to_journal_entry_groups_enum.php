<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE journal_entry_groups
            MODIFY COLUMN type ENUM(
                'transfer_initiation',
                'transfer_completion',
                'transfer_reversal',
                'transfer_credit',
                'transfer_debit',
                'fee_collection',
                'guarantee_contribution',
                'guarantee_payout',
                'escrow_release',
                'adjustment',
                'transfer_escrow_release'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE journal_entry_groups
            MODIFY COLUMN type ENUM(
                'transfer_initiation',
                'transfer_completion',
                'transfer_reversal',
                'transfer_credit',
                'transfer_debit',
                'fee_collection',
                'guarantee_contribution',
                'guarantee_payout',
                'escrow_release',
                'adjustment'
            ) NOT NULL
        ");
    }
};
