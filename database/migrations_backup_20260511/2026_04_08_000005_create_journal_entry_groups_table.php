<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('journal_entry_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('currency_code', 3);
            $table->decimal('total_amount', 20, 6);
            $table->enum('type', [
                'transfer_initiation',
                'transfer_completion',
                'transfer_reversal',
                'fee_collection',
                'guarantee_contribution',
                'guarantee_payout',
                'escrow_release',
                'adjustment'
            ]);
            $table->string('reference')->unique(); // prevents duplicate posting
            $table->enum('status', ['pending','posted','reversed'])->default('pending');
            $table->foreignId('reversal_of_group_id')
                ->nullable()
                ->constrained('journal_entry_groups')
                ->onDelete('restrict'); // links reversal back to original group
            $table->string('description')->nullable();
            $table->boolean('is_balanced')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['type', 'posted_at']);
            $table->index(['status', 'created_at']);
            $table->index('reference');
        });
    }
    public function down(): void { Schema::dropIfExists('journal_entry_groups'); }
};
