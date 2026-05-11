<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code')->unique();
            $table->enum('type', ['user_wallet', 'escrow', 'fee', 'guarantee', 'system', 'partner']);
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('owner_type')->nullable();
            $table->string('corridor')->nullable();
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'currency_code']);
            $table->index(['owner_id', 'owner_type']);
            $table->index('corridor');
        });

        Schema::create('account_balances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('account_id')->unique()->constrained('accounts');
            $table->decimal('balance', 20, 6)->default(0);
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('last_journal_entry_id')->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('account_id');
        });

        Schema::create('journal_entry_groups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('currency_code', 3);
            $table->decimal('total_amount', 20, 6);
            $table->enum('type', [
                'transfer_initiation', 'transfer_completion', 'transfer_reversal',
                'transfer_credit', 'transfer_debit', 'fee_collection',
                'guarantee_contribution', 'guarantee_payout', 'escrow_release',
                'adjustment', 'transfer_escrow_release'
            ]);
            $table->string('reference')->unique();
            $table->enum('status', ['pending', 'posted', 'reversed'])->default('pending');
            $table->foreignId('reversal_of_group_id')->nullable()->constrained('journal_entry_groups');
            $table->string('description')->nullable();
            $table->boolean('is_balanced')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['type', 'posted_at']);
            $table->index(['status', 'created_at']);
            $table->index('reference');
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('group_id')->constrained('journal_entry_groups');
            $table->foreignId('account_id')->constrained('accounts');
            $table->enum('entry_type', ['debit', 'credit']);
            $table->decimal('amount', 20, 6);
            $table->string('currency_code', 3);
            $table->string('description')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'posted_at']);
            $table->index(['group_id', 'entry_type']);
            $table->index('posted_at');
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->date('starts_on')->unique();
            $table->date('ends_on')->unique();
            $table->enum('status', ['open', 'closed', 'locked'])->default('open');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->json('trial_balance_snapshot')->nullable();
            $table->json('balance_sheet_snapshot')->nullable();
            $table->json('profit_loss_snapshot')->nullable();
            $table->json('cash_flow_snapshot')->nullable();
            $table->json('locked_trial_balance')->nullable();
            $table->json('locked_balance_sheet')->nullable();
            $table->json('locked_profit_loss')->nullable();
            $table->json('locked_cash_flow')->nullable();
            $table->decimal('total_assets', 20, 6)->nullable();
            $table->decimal('total_liabilities', 20, 6)->nullable();
            $table->decimal('total_equity', 20, 6)->nullable();
            $table->decimal('net_profit', 20, 6)->nullable();
            $table->decimal('net_cash_change', 20, 6)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('reconciliation_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('account_id')->constrained('accounts');
            $table->date('snapshot_date');
            $table->decimal('computed_balance', 20, 6);
            $table->decimal('expected_balance', 20, 6);
            $table->decimal('variance', 20, 6)->default(0);
            $table->enum('status', ['matched', 'mismatch', 'under_review', 'resolved'])->default('matched');
            $table->text('notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['account_id', 'snapshot_date']);
            $table->index(['status', 'snapshot_date']);
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_snapshots');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('journal_entry_groups');
        Schema::dropIfExists('account_balances');
        Schema::dropIfExists('accounts');
    }
};
