<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OtpService
{
    private int $expiryMinutes = 10;
    private int $maxAttempts   = 3;

    /**
     * Generate and send an OTP to the user's phone.
     * Invalidates any previous unused OTPs of the same type.
     */
    public function send(User $user, string $type): void
    {
        $phone = $user->phone;

        if (empty($phone)) {
            throw new \RuntimeException(
                "Cannot send OTP — user {$user->id} has no phone number."
            );
        }

        // Invalidate previous unused OTPs of same type
        OtpCode::where('user_id', $user->id)
            ->where('type', $type)
            ->where('is_used', false)
            ->update(['is_used' => true, 'used_at' => now()]);

        // Generate 6-digit OTP
        $code     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = Hash::make($code);

        OtpCode::create([
            'user_id'        => $user->id,
            'code_hash'      => $codeHash,
            'type'           => $type,
            'delivery_phone' => $phone,
            'is_used'        => false,
            'expires_at'     => now()->addMinutes($this->expiryMinutes),
        ]);

        // Send via SmsService
        app(SmsService::class)->sendOtp([
            'phone' => $phone,
            'otp'   => $code,
        ]);

        Log::info('[OtpService] OTP sent', [
            'user_id' => $user->id,
            'type'    => $type,
            'phone'   => substr($phone, 0, 6) . '****',
        ]);
    }

    /**
     * Verify an OTP code submitted by the user.
     * Returns true if valid, false if invalid or expired.
     * Marks OTP as used on success.
     */
    public function verify(User $user, string $type, string $code): bool
    {
        $otp = OtpCode::where('user_id', $user->id)
            ->where('type', $type)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if (!$otp) {
            Log::warning('[OtpService] No valid OTP found', [
                'user_id' => $user->id,
                'type'    => $type,
            ]);
            return false;
        }

        if (!Hash::check($code, $otp->code_hash)) {
            Log::warning('[OtpService] Invalid OTP submitted', [
                'user_id' => $user->id,
                'type'    => $type,
            ]);
            return false;
        }

        // Mark as used
        $otp->update([
            'is_used' => true,
            'used_at' => now(),
        ]);

        Log::info('[OtpService] OTP verified successfully', [
            'user_id' => $user->id,
            'type'    => $type,
        ]);

        return true;
    }
}
