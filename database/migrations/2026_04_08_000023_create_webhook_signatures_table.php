<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('webhook_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->onDelete('restrict');
            // Never store raw secret — store encrypted version
            $table->text('secret_encrypted');
            $table->string('algorithm')->default('sha256');
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
