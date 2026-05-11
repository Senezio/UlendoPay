<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipients', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users');
            $table->string('full_name');
            $table->text('full_name_encrypted')->nullable();
            $table->text('phone_encrypted')->nullable();
            $table->string('phone_hash', 64)->nullable();
            $table->string('phone')->nullable();
            $table->string('country_code', 3);
            $table->enum('payment_method', ['mobile_money', 'bank_transfer', 'cash_pickup', 'wallet_transfer']);
            $table->string('mobile_network')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->text('bank_account_encrypted')->nullable();
            $table->string('bank_branch_code')->nullable();
            $table->string('wallet_account', 20)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('reference_number')->unique();
            $table->foreignId('sender_id')->constrained('users');
            $table->foreignId('recipient_id')->constrained('recipients');
            $table->foreignId('rate_lock_id')->constrained('rate_locks');
            $table->foreignId('partner_id')->nullable()->constrained('partners');
            $table->foreignId('journal_entry_group_id')->nullable()->constrained('journal_entry_groups');
            $table->decimal('send_amount', 20, 6);
            $table->string('send_currency', 3);
            $table->decimal('receive_amount', 20, 6);
            $table->string('receive_currency', 3);
            $table->decimal('locked_rate', 20, 8);
            $table->decimal('fee_amount', 20, 6);
            $table->decimal('guarantee_contribution', 20, 6)->default(0);
            $table->string('partner_reference')->nullable();
            $table->string('transfer_purpose')->nullable();
            $table->enum('status', [
                'initiated', 'escrowed', 'processing', 'retrying',
                'completed', 'failed', 'refund_pending', 'refunded',
                'disputed', 'pending_claim'
            ])->default('initiated');
            $table->boolean('flagged_for_review')->default(false);
            $table->integer('risk_score')->default(0);
            $table->json('fraud_context')->nullable();
            $table->text('failure_reason')->nullable();
            $table->integer('disbursement_attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('escrowed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['sender_id', 'status']);
            $table->index(['status', 'next_attempt_at']);
            $table->index('reference_number');
            $table->index('created_at');
            $table->index(['sender_id', 'created_at']);
        });

        Schema::create('disbursement_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('transaction_id')->constrained('transactions');
            $table->foreignId('partner_id')->constrained('partners');
            $table->integer('attempt_number');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->enum('status', ['pending', 'success', 'failed', 'timeout']);
            $table->integer('response_time_ms')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['transaction_id', 'status']);
            $table->index('attempted_at');
        });

        Schema::create('pending_claims', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('transaction_id')->constrained('transactions');
            $table->string('recipient_phone_hash', 64);
            $table->string('recipient_phone_masked');
            $table->decimal('amount', 20, 6);
            $table->string('currency_code', 3);
            $table->enum('status', ['pending', 'claimed', 'expired', 'refunded'])->default('pending');
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_phone_hash', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('top_ups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->decimal('amount', 20, 6);
            $table->string('currency_code', 3);
            $table->string('phone_number', 20);
            $table->string('mobile_operator');
            $table->string('country_code', 3);
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable()->unique();
            $table->string('correspondent')->nullable();
            $table->json('provider_request_payload')->nullable();
            $table->json('provider_response_payload')->nullable();
            $table->json('provider_webhook_payload')->nullable();
            $table->enum('status', ['initiated', 'pending', 'completed', 'failed', 'cancelled'])->default('initiated');
            $table->text('failure_reason')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('reference');
            $table->index('provider');
        });

        Schema::create('withdrawals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->decimal('amount', 20, 6);
            $table->string('currency_code', 3);
            $table->enum('withdrawal_method', ['mobile_money', 'bank_transfer'])->default('mobile_money');
            $table->string('phone_number', 20)->nullable();
            $table->string('mobile_operator')->nullable();
            $table->string('country_code', 3);
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable()->unique();
            $table->string('correspondent')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_branch_code')->nullable();
            $table->string('bank_name')->nullable();
            $table->json('provider_request_payload')->nullable();
            $table->json('provider_response_payload')->nullable();
            $table->json('provider_webhook_payload')->nullable();
            $table->enum('status', ['initiated', 'pending', 'completed', 'failed', 'cancelled'])->default('initiated');
            $table->text('failure_reason')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('reference');
            $table->index('provider');
            $table->index(['withdrawal_method', 'status']);
        });

        Schema::create('fraud_alerts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('rule_triggered');
            $table->integer('risk_score')->default(0);
            $table->json('context');
            $table->enum('status', ['new', 'reviewing', 'cleared', 'confirmed'])->default('new');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index('risk_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_alerts');
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('top_ups');
        Schema::dropIfExists('pending_claims');
        Schema::dropIfExists('disbursement_attempts');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('recipients');
    }
};
