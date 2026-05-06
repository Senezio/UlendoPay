<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Compliance\ComplianceService;
use Illuminate\Support\Facades\Log;

class UserObserver
{
    public function __construct(private ComplianceService $compliance) {}

    public function created(User $user): void
    {
        try {
            $this->compliance->fullScreen($user, "registration");
        } catch (\Throwable $e) {
            Log::error("Compliance screening failed at registration", [
                "user_id" => $user->id,
                "error"   => $e->getMessage(),
            ]);
        }
    }

    public function updating(User $user): void
    {
        if ($user->isDirty("name")) {
            try {
                $this->compliance->fullScreen($user, "name_change");
            } catch (\Throwable $e) {
                Log::error("Compliance screening failed on name change", [
                    "user_id" => $user->id,
                    "error"   => $e->getMessage(),
                ]);
            }
        }
    }
}
