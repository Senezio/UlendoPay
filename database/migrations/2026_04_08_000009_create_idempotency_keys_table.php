<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// request_hash: SHA256 of (key + payload) — detects mismatched retries
// locked_until: prevents concurrent execution of same key
return new class extends Migration {
    public function up(): void {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('request_hash');   // SHA256(key + request payload)
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->string('endpoint');
            $table->json('response_body')->nullable();
            $table->integer('response_status')->nullable();
            $table->enum('status', ['processing','completed','failed'])->default('processing');
            $table->timestamp('locked_until')->nullable(); // optimistic lock for concurrent requests
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'status']);
            $table->index('expires_at');
            $table->index('locked_until');
        });
    }
    public function down(): void { Schema::dropIfExists('idempotency_keys'); }
};
