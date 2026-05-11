<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Transfer tiers table
        Schema::create('transfer_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // unverified, basic, verified
            $table->string('label'); // Display name
            $table->decimal('daily_limit', 20, 6);
            $table->decimal('monthly_limit', 20, 6);
            $table->decimal('per_transaction_limit', 20, 6);
            $table->decimal('fee_discount_percent', 5, 2)->default(0); // % off standard fee
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Add tier to users
        Schema::table('users', function (Blueprint $table) {
            $table->string('tier')->default('unverified')->after('kyc_status');
            $table->string('referral_code', 10)->nullable()->unique()->after('tier');
            $table->foreignId('referred_by')->nullable()->constrained('users')->onDelete('set null')->after('referral_code');
            $table->decimal('referral_discount_percent', 5, 2)->default(0)->after('referred_by');
        });

        // Referrals table
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('referred_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['pending', 'qualified', 'rewarded'])->default('pending');
            $table->decimal('referrer_discount_percent', 5, 2)->default(0);
            $table->decimal('referred_discount_percent', 5, 2)->default(0);
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tier', 'referral_code', 'referred_by', 'referral_discount_percent']);
        });
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('transfer_tiers');
    }
};
