<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('disbursement_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->onDelete('restrict');
            $table->foreignId('partner_id')->constrained()->onDelete('restrict');
            $table->integer('attempt_number');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->enum('status', ['pending','success','failed','timeout']);
            $table->integer('response_time_ms')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['transaction_id', 'status']);
            $table->index('attempted_at');
        });
    }
    public function down(): void { Schema::dropIfExists('disbursement_attempts'); }
};
