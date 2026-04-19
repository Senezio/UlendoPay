<?php

namespace App\Services;

use App\Models\TwoFactorAuth;
use App\Models\User;
use Illuminate\Support\Str;
use OTPHP\TOTP;

class TwoFactorAuthService
{
    /**
     * Generate a new TOTP secret and store it (disabled until confirmed).
     */
    public function setup(User $user): array
    {
        $existing = TwoFactorAuth::where('user_id', $user->id)->first();

        // Return existing secret if one already exists (enabled or pending confirmation)
        // Never silently rotate - user may have already scanned the QR code
        if ($existing) {
            $secret = $existing->getSecret();
            return [
                'secret'         => $secret,
                'qr_code_url'    => $this->generateQrUrl($user, $secret),
                'recovery_codes' => $existing->getRecoveryCodes(),
            ];
        }

        // No record exists yet - generate fresh secret
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = implode('', array_map(fn() => $chars[random_int(0, 31)], array_fill(0, 32, null)));

        $twoFactor = TwoFactorAuth::create([
            'user_id'                  => $user->id,
            'secret_encrypted'         => encrypt($secret),
            'recovery_codes_encrypted' => encrypt(json_encode($this->generateRecoveryCodes())),
            'is_enabled'               => false,
        ]);

        return [
            'secret'         => $secret,
            'qr_code_url'    => $this->generateQrUrl($user, $secret),
            'recovery_codes' => $twoFactor->getRecoveryCodes(),
        ];
    }

    /**
     * Verify a TOTP code and enable 2FA if not already enabled.
     */
    public function verify(User $user, string $code): bool
    {
        $twoFactor = TwoFactorAuth::where('user_id', $user->id)->first();

        if (!$twoFactor) {
            return false;
        }

        $totp = TOTP::create($twoFactor->getSecret());

        if ($totp->verify($code, null, 1)) {
            if (!$twoFactor->is_enabled) {
                $twoFactor->update([
                    'is_enabled' => true,
                    'enabled_at' => now(),
                ]);
            }
            $twoFactor->markUsed();
            return true;
        }

        // Check recovery codes
        $codes = $twoFactor->getRecoveryCodes();
        if (in_array($code, $codes)) {
            $codes = array_filter($codes, fn($c) => $c !== $code);
            $twoFactor->setRecoveryCodes(array_values($codes));
            $twoFactor->markUsed();
            return true;
        }

        return false;
    }

    /**
     * Disable 2FA for a user.
     */
    public function disable(User $user): void
    {
        TwoFactorAuth::where('user_id', $user->id)
            ->update(['is_enabled' => false]);
    }

    /**
     * Check if user has 2FA enabled.
     */
    public function isEnabled(User $user): bool
    {
        return TwoFactorAuth::where('user_id', $user->id)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Generate recovery codes.
     */
    private function generateRecoveryCodes(int $count = 8): array
    {
        return array_map(
            fn() => strtoupper(Str::random(5)) . '-' . strtoupper(Str::random(5)),
            array_fill(0, $count, null)
        );
    }

    /**
     * Generate QR code URL for authenticator apps.
     */
    private function generateQrUrl(User $user, string $secret): string
    {
        $label  = urlencode('UlendoPay:' . ($user->email ?? $user->phone));
        $issuer = urlencode('UlendoPay');
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }
}
