<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add PIN and phone_verified_at to users
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'pin')) {
                $table->string('pin')->nullable()->after('password');
            }
            if (!Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('pin');
            }
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('phone_verified_at');
            }
            if (!Schema::hasColumn('users', 'last_login_method')) {
                $table->enum('last_login_method', ['phone_pin','email_password'])
                    ->nullable()->after('last_login_at');
            }
        });

        // OTP codes table
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->string('code_hash');
            $table->enum('type', [
                'phone_verification',
                'login_2fa',
                'password_reset',
                'pin_reset',
            ]);
            $table->string('delivery_phone', 20);
            $table->boolean('is_used')->default(false);
            $table->dateTime('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'type', 'is_used']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'pin',
                'phone_verified_at',
                'last_login_at',
                'last_login_method',
            ]);
        });
        Schema::dropIfExists('otp_codes');
    }
};
