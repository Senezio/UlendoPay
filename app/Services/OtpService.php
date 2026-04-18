<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    private int $expiryMinutes = 10;

    /**
     * Send OTP via SMS (default) or email.
     */
    public function send(User $user, string $type, string $channel = 'sms'): void
    {
        // Invalidate previous OTPs of same type
        OtpCode::where("user_id", $user->id)
            ->where("type", $type)
            ->where("is_used", false)
            ->update(["is_used" => true]);

        $code = str_pad(random_int(0, 999999), 6, "0", STR_PAD_LEFT);

        OtpCode::create([
            "user_id"        => $user->id,
            "code_hash"      => Hash::make($code),
            "type"           => $type,
            "delivery_phone" => $channel === 'sms' ? $user->phone : null,
            "delivery_email" => $channel === 'email' ? $user->email : null,
            "is_used"        => false,
            "expires_at"     => now()->addMinutes($this->expiryMinutes),
        ]);

        if ($channel === 'email') {
            $this->sendViaEmail($user, $code, $type);
        } else {
            $this->sendViaSms($user, $code);
        }

        Log::info("[OtpService] OTP sent via {$channel} to user {$user->id}");
    }

    private function sendViaSms(User $user, string $code): void
    {
        $phone = $user->phone;

        if (empty($phone)) {
            throw new \RuntimeException("User has no phone number record.");
        }

        // Duplicate phone check
        $exists = User::where("phone_hash", $user->phone_hash)
            ->where("id", "!=", $user->id)
            ->whereNotNull("phone_verified_at")
            ->exists();

        if ($exists) {
            throw new \RuntimeException("This number is already linked to an active account.");
        }

        app(SmsService::class)->sendOtp([
            "phone"        => $phone,
            "otp"          => $code,
            "country_code" => $user->country_code,
        ]);
    }

    private function sendViaEmail(User $user, string $code, string $type): void
    {
        if (empty($user->email)) {
            throw new \RuntimeException("User has no email address.");
        }

        $subject = match($type) {
            'password_reset' => 'Reset Your UlendoPay Password',
            'login_2fa'      => 'Your UlendoPay Login Code',
            default          => 'Your UlendoPay Verification Code',
        };

        $typeLabel = match($type) {
            'password_reset' => 'reset your password',
            'login_2fa'      => 'complete your login',
            default          => 'verify your identity',
        };

        Mail::send([], [], function ($message) use ($user, $code, $subject, $typeLabel) {
            $message->to($user->email, $user->name)
                ->subject($subject)
                ->html("
                    <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; padding: 24px;'>
                        <div style='text-align: center; margin-bottom: 24px;'>
                            <h2 style='color: #E87722;'>UlendoPay</h2>
                        </div>
                        <p>Hi {$user->name},</p>
                        <p>Use the code below to {$typeLabel}. This code expires in 10 minutes.</p>
                        <div style='text-align: center; margin: 32px 0;'>
                            <span style='font-size: 36px; font-weight: bold; letter-spacing: 12px; color: #0f172a; background: #f1f5f9; padding: 16px 24px; border-radius: 12px;'>{$code}</span>
                        </div>
                        <p style='color: #64748b; font-size: 13px;'>If you did not request this, please ignore this email.</p>
                        <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;'>
                        <p style='color: #94a3b8; font-size: 12px; text-align: center;'>UlendoPay — Borderless Money</p>
                    </div>
                ");
        });
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
