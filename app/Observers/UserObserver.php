<?php

namespace App\Observers;

use App\Models\User;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Log;

class UserObserver
{
    /**
     * Queue compliance screening via outbox instead of running synchronously.
     * This prevents stack overflows and connection spikes during registration.
     */
    public function created(User $user): void
    {
        try {
            OutboxEvent::create([
                'event_type'      => 'compliance_screening',
                'payload'         => ['user_id' => $user->id, 'trigger' => 'registration'],
                'status'          => 'pending',
                'next_attempt_at' => now(),
                'max_attempts'    => 3,
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to queue compliance screening at registration", [
                "user_id" => $user->id,
                "error"   => $e->getMessage(),
            ]);
        }
    }

    public function updating(User $user): void
    {
        if ($user->isDirty("name")) {
            try {
                OutboxEvent::create([
                    'event_type'      => 'compliance_screening',
                    'payload'         => ['user_id' => $user->id, 'trigger' => 'name_change'],
                    'status'          => 'pending',
                    'next_attempt_at' => now(),
                    'max_attempts'    => 3,
                ]);
            } catch (\Throwable $e) {
                Log::error("Failed to queue compliance screening on name change", [
                    "user_id" => $user->id,
                    "error"   => $e->getMessage(),
                ]);
            }
        }
    }
}
