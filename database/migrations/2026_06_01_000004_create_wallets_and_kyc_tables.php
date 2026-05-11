<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('account_id')->constrained('accounts');
            $table->string('currency_code', 3);
            $table->enum('status', ['active', 'frozen', 'closed'])->default('active');
            $table->timestamps();

            $table->unique(['user_id', 'currency_code']);
            $table->index('account_id');
        });

        Schema::create('kyc_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users');
            $table->enum('document_type', [
                'passport', 'national_id', 'drivers_license', 'utility_bill', 'bank_statement'
            ]);
            $table->string('document_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('file_path');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('requested_tier')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('user_bank_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('bank_name');
            $table->string('bank_code')->nullable();
            $table->text('account_number_encrypted');
            $table->string('account_number_masked', 20);
            $table->string('account_name');
            $table->string('branch_code')->nullable();
            $table->string('currency_code', 3);
            $table->string('country_code', 3);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('transfer_tiers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->unsignedTinyInteger('level')->default(0);
            $table->string('label');
            $table->decimal('daily_limit', 20, 6);
            $table->decimal('monthly_limit', 20, 6);
            $table->decimal('per_transaction_limit', 20, 6);
            $table->decimal('fee_discount_percent', 5, 2)->default(0);
            $table->string('limit_currency', 3)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('level');
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'qualified', 'rewarded'])->default('pending');
            $table->decimal('referrer_discount_percent', 5, 2)->default(0);
            $table->decimal('referred_discount_percent', 5, 2)->default(0);
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key')->unique();
            $table->string('request_hash');
            $table->foreignId('user_id')->constrained('users');
            $table->string('endpoint');
            $table->json('response_body')->nullable();
            $table->integer('response_status')->nullable();
            $table->enum('status', ['processing', 'completed', 'failed'])->default('processing');
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
            $table->index('locked_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('transfer_tiers');
        Schema::dropIfExists('user_bank_accounts');
        Schema::dropIfExists('kyc_records');
        Schema::dropIfExists('wallets');
    }
};
