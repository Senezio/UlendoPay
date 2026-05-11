<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->text('phone_encrypted')->nullable();
            $table->string('phone_hash', 64)->nullable()->unique();
            $table->string('country_code', 3)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->enum('kyc_status', ['none', 'pending', 'verified', 'rejected'])->default('none');
            $table->string('tier')->default('unverified');
            $table->string('referral_code', 10)->nullable()->unique();
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->boolean('is_staff')->default(false);
            $table->enum('role', ['super_admin', 'kyc_reviewer', 'finance_officer', 'support_agent'])->nullable();
            $table->timestamp('kyc_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('pin')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->enum('last_login_method', ['phone_pin', 'email_password'])->nullable();
            $table->timestamp('last_screened_at')->nullable();
            $table->rememberToken();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('referral_discount_percent', 5, 2)->default(0);
            $table->timestamps();

            $table->index('kyc_status');
            $table->index('status');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('two_factor_auth', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->text('secret_encrypted');
            $table->text('recovery_codes_encrypted');
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_enabled']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->nullable();
            $table->string('action');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('action');
            $table->index('created_at');
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users');
            $table->string('code_hash');
            $table->enum('type', ['phone_verification', 'login_2fa', 'password_reset', 'pin_reset']);
            $table->string('delivery_phone', 20)->nullable();
            $table->string('delivery_email')->nullable();
            $table->boolean('is_used')->default(false);
            $table->dateTime('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'type', 'is_used']);
            $table->index('expires_at');
        });

        Schema::create('rate_limit_buckets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key');
            $table->string('action');
            $table->integer('attempts')->default(0);
            $table->timestamp('window_start')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('blocked_until')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['key', 'action', 'window_start']);
            $table->index(['key', 'action']);
            $table->index('blocked_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_buckets');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('two_factor_auth');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
    }
};
