<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\User;
use App\Services\RateLimiterService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

trait AuthHelpers
{
    protected function throttle(string $key, int $maxAttempts, int $decayMinutes): void
    {
        app(RateLimiterService::class)->attempt($key, explode(':', $key)[0]);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'throttle' => ["Too many attempts. Try again in {$seconds} seconds."],
            ]);
        }

        RateLimiter::hit($key, $decayMinutes * 60);
    }

    protected function formatUser(User $user): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'phone'          => $user->phone,
            'country_code'   => $user->country_code,
            'kyc_status'     => $user->kyc_status,
            'tier'           => $user->tier,
            'status'         => $user->status,
            'is_staff'       => (bool) $user->is_staff,
            'role'           => $user->role,
            'phone_verified' => $user->isPhoneVerified(),
            'has_pin'        => !is_null($user->pin),
            'has_password'   => !is_null($user->password),
        ];
    }
}
