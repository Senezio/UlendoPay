<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['mobile_money', 'bank', 'card']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('partner_corridors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->decimal('fee_percent', 8, 4)->default(0);
            $table->decimal('fee_flat', 20, 6)->default(0);
            $table->decimal('guarantee_percent', 8, 6)->default(0);
            $table->decimal('min_amount', 20, 6)->default(0);
            $table->decimal('max_amount', 20, 6)->default(0);
            $table->integer('priority')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['from_currency', 'to_currency', 'is_active']);
        });

        Schema::create('webhook_signatures', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('partner_id')->constrained('partners');
            $table->text('secret_encrypted');
            $table->string('algorithm');
            $table->boolean('is_active')->default(true);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'is_active']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->decimal('rate', 20, 8);
            $table->decimal('buying_rate', 20, 8)->nullable();
            $table->decimal('middle_rate', 20, 8)->nullable();
            $table->decimal('selling_rate', 20, 8)->nullable();
            $table->decimal('inverse_rate', 20, 8);
            $table->decimal('margin_percent', 8, 4)->default(0);
            $table->string('source')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_stale')->default(false);
            $table->string('stale_reason')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['from_currency', 'to_currency', 'is_active']);
            $table->index('expires_at');
        });

        Schema::create('rate_locks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('exchange_rate_id')->constrained('exchange_rates');
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->decimal('locked_rate', 20, 8);
            $table->decimal('fee_percent', 8, 4)->default(0);
            $table->decimal('fee_flat', 20, 6)->default(0);
            $table->decimal('guarantee_percent', 8, 6)->default(0);
            $table->decimal('send_amount', 20, 6)->nullable();
            $table->enum('status', ['active', 'used', 'expired'])->default('active');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_locks');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('webhook_signatures');
        Schema::dropIfExists('partner_corridors');
        Schema::dropIfExists('partners');
    }
};
