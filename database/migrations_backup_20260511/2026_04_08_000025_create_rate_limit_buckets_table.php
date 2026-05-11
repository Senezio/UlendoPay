<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('rate_limit_buckets', function (Blueprint $table) {
            $table->id();
            // key = "user:123", "ip:192.168.1.1", "phone:+265..."
            $table->string('key');
            $table->string('action'); // login, transfer, quote, otp
            $table->integer('attempts')->default(0);
            $table->timestamp('window_start');
            $table->timestamp('blocked_until')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['key', 'action', 'window_start']);
            $table->index(['key', 'action']);
            $table->index('blocked_until');
        });
    }
    public function down(): void {
        Schema::dropIfExists('rate_limit_buckets');
    }
};
