<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_tokens')) {
            return;
        }

        Schema::create('device_tokens', function (Blueprint ) {
            ->id();
            ->foreignId('user_id')->constrained()->cascadeOnDelete();
            ->string('token', 512);
            ->string('platform', 10)->default('android');
            ->boolean('is_active')->default(true);
            ->timestamps();

            ->unique(['user_id', 'token']);
            ->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
