<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('fraud_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->nullable()->constrained()->onDelete('restrict');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('restrict');
            $table->string('rule_triggered');
            $table->integer('risk_score')->default(0);
            $table->json('context');
            $table->enum('status', ['new','reviewing','cleared','confirmed'])->default('new');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index('risk_score');
        });
    }
    public function down(): void {
        Schema::dropIfExists('fraud_alerts');
    }
};
