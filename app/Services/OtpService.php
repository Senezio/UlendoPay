<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OtpService
{
    private int $expiryMinutes = 10;

    public function send(User $user, string $type): void
    {
        // Use the phone attribute from the model (assuming it handles encryption/decryption)
        $phone = $user->phone; 

        if (empty($phone)) {
            throw new \RuntimeException("User has no phone number record.");
        }

        // Use phone_hash for the duplicate check - this exists in your migration
        $exists = User::where("phone_hash", $user->phone_hash)
            ->where("id", "!=", $user->id)
            ->whereNotNull("phone_verified_at")
            ->exists();

        if ($exists) {
            throw new \RuntimeException("This number is already linked to an active account.");
        }

        // Invalidate previous OTPs
        OtpCode::where("user_id", $user->id)
            ->where("type", $type)
            ->where("is_used", false)
            ->update(["is_used" => true]);

        $code = str_pad(random_int(0, 999999), 6, "0", STR_PAD_LEFT);

        OtpCode::create([
            "user_id"        => $user->id,
            "code_hash"      => Hash::make($code),
            "type"           => $type,
            "delivery_phone" => $phone,
            "is_used"        => false,
            "expires_at"     => now()->addMinutes($this->expiryMinutes),
        ]);

        app(SmsService::class)->sendOtp([
            "phone"        => $phone,
            "otp"          => $code,
            "country_code" => $user->country_code,
        ]);

        Log::info("[OtpService] OTP sent to {$user->id}");
    }

    public function verify(User $user, string $type, string $code): bool
    {
        $otp = OtpCode::where("user_id", $user->id)
            ->where("type", $type)
            ->where("is_used", false)
            ->where("expires_at", ">", now())
            ->latest("created_at")
            ->first();

        if (!$otp || !Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->update([
            "is_used" => true,
            "used_at" => now(),
        ]);

        return true;
    }
}
