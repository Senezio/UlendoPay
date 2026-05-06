<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source');           // 'pawapay' | 'mtn'
            $table->string('direction');        // 'topup' | 'withdrawal'
            $table->string('provider_reference')->nullable();
            $table->string('status')->nullable();
            $table->string('outcome');          // 'accepted' | 'rejected' | 'failed' | 'duplicate' | 'not_found'
            $table->boolean('signature_valid')->default(true);
            $table->json('payload')->nullable();
            $table->json('headers')->nullable();
            $table->text('error')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->index(['source', 'received_at']);
            $table->index(['provider_reference']);
            $table->index(['outcome', 'received_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('webhook_logs');
    }
};
