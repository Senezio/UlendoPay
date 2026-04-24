<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('webhook_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->onDelete('restrict');
            // Stores encrypted signing material — either a shared secret (HMAC)
            // or a public key (ECDSA). Never store raw values.
            $table->text('secret_encrypted');
            // Algorithm identifier — must match the verifier in VerifiesWebhookSignature trait.
            // Supported: 'ecdsa-p256-sha256', 'hmac-sha256'
            $table->string('algorithm');
            $table->boolean('is_active')->default(true);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'is_active']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('webhook_signatures');
    }
};
