<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class OtpService
{
    private int $expiryMinutes = 10;

    public function send(User $user, string $type, ?string $channel = null): void
    {
        OtpCode::where('user_id', $user->id)
            ->where('type', $type)
            ->where('is_used', false)
            ->update(['is_used' => true]);

        $code        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $sent        = false;
        $usedChannel = 'sms';

        if ($channel === 'email') {
            if (empty($user->email)) {
                throw new \RuntimeException('User has no email address for forced email delivery.');
            }
            $this->sendViaEmail($user, $code, $type);
            $sent        = true;
            $usedChannel = 'email';
        } elseif ($channel === 'sms') {
            $this->sendViaSms($user, $code);
            $sent        = true;
            $usedChannel = 'sms';
        } else {
            if (!empty($user->email)) {
                try {
                    $this->sendViaEmail($user, $code, $type);
                    $sent        = true;
                    $usedChannel = 'email';
                } catch (Throwable $e) {
                    Log::warning('[OtpService] Email failed, falling back to SMS', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            if (!$sent) {
                $this->sendViaSms($user, $code);
                $usedChannel = 'sms';
            }
        }

        OtpCode::create([
            'user_id'        => $user->id,
            'code_hash'      => Hash::make($code),
            'type'           => $type,
            'delivery_phone' => $usedChannel === 'sms'   ? $user->phone : null,
            'delivery_email' => $usedChannel === 'email' ? $user->email : null,
            'is_used'        => false,
            'expires_at'     => now()->addMinutes($this->expiryMinutes),
        ]);

        Log::info('[OtpService] OTP sent via ' . $usedChannel . ' to user ' . $user->id);
    }

    private function sendViaSms(User $user, string $code): void
    {
        if (empty($user->phone)) {
            throw new \RuntimeException('User has no phone number record.');
        }

        $exists = User::where('phone_hash', $user->phone_hash)
            ->where('id', '!=', $user->id)
            ->whereNotNull('phone_verified_at')
            ->exists();

        if ($exists) {
            throw new \RuntimeException('This number is already linked to an active account.');
        }

        app(SmsService::class)->sendOtp([
            'phone'        => $user->phone,
            'otp'          => $code,
            'country_code' => $user->country_code,
        ]);
    }

    private function sendViaEmail(User $user, string $code, string $type): void
    {
        if (empty($user->email)) {
            throw new \RuntimeException('User has no email address.');
        }

        $subject = match($type) {
            'password_reset'     => 'Reset Your UlendoPay Password',
            'login_2fa'          => 'Your UlendoPay Login Code',
            'email_verification' => 'Verify Your UlendoPay Email',
            'pin_reset'          => 'Reset Your UlendoPay PIN',
            default              => 'Your UlendoPay Verification Code',
        };

        $headline = match($type) {
            'password_reset'     => 'Password Reset Request',
            'login_2fa'          => 'Confirm Your Login',
            'email_verification' => 'Verify Your Email Address',
            'pin_reset'          => 'PIN Reset Request',
            default              => 'Verify Your Identity',
        };

        $bodyText = match($type) {
            'password_reset'     => 'We received a request to reset your UlendoPay password. Use the code below to proceed. If you did not request this, no action is needed.',
            'login_2fa'          => 'A login attempt was detected on your UlendoPay account. Enter the code below to complete your sign-in. If this was not you, secure your account immediately.',
            'email_verification' => 'Welcome to UlendoPay. Please use the code below to verify your email address and activate your account.',
            'pin_reset'          => 'We received a request to reset your UlendoPay PIN. Use the code below to continue. If you did not request this, please contact our support team.',
            default              => 'Use the code below to verify your identity. This code is valid for 10 minutes and should not be shared with anyone.',
        };

        $year = date('Y');
        $name = $user->name;

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

  <!-- Top navy bar with logo -->
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#001f3f;">
    <tr>
      <td style="padding:24px 40px;">
        <img src="https://ulendopay.com/logo.png" alt="UlendoPay" height="32" style="display:block;">
      </td>
    </tr>
  </table>

  <!-- Orange accent line -->
  <table width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td style="background-color:#ff851b;height:3px;font-size:0;line-height:0;">&nbsp;</td>
    </tr>
  </table>

  <!-- Main content -->
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;">
    <tr>
      <td style="padding:48px 40px 0;">
        <p style="margin:0 0 4px;color:#ff851b;font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;">UlendoPay Security</p>
        <h1 style="margin:0 0 24px;color:#001f3f;font-size:28px;font-weight:800;line-height:1.2;">{$headline}</h1>
        <p style="margin:0 0 6px;color:#ff851b;font-size:14px;font-weight:600;">Hi {$name},</p>
        <p style="margin:0;color:#4a5568;font-size:15px;line-height:1.8;max-width:480px;">{$bodyText}</p>
      </td>
    </tr>

    <!-- Divider -->
    <tr>
      <td style="padding:36px 40px 0;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr><td style="border-top:1px solid #e8edf2;font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>
      </td>
    </tr>

    <!-- OTP -->
    <tr>
      <td style="padding:32px 40px 0;">
        <p style="margin:0 0 10px;color:#a0aec0;font-size:11px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;">Your one-time code</p>
        <p style="margin:0 0 10px;color:#001f3f;font-size:52px;font-weight:800;letter-spacing:6px;line-height:1;">{$code}</p>
        <p style="margin:0;color:#a0aec0;font-size:13px;">Expires in <strong style="color:#4a5568;">10 minutes</strong>. Do not share this code.</p>
      </td>
    </tr>

    <!-- Divider -->
    <tr>
      <td style="padding:36px 40px 0;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr><td style="border-top:1px solid #e8edf2;font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>
      </td>
    </tr>

    <!-- Security notice -->
    <tr>
      <td style="padding:24px 40px 48px;">
        <p style="margin:0 0 6px;color:#c05621;font-size:13px;font-weight:700;">Security Notice</p>
        <p style="margin:0;color:#718096;font-size:13px;line-height:1.8;max-width:480px;">UlendoPay will never ask for this code by phone or email. If you did not initiate this request, contact <a href="mailto:support@ulendopay.com" style="color:#ff851b;text-decoration:none;font-weight:600;">support@ulendopay.com</a> immediately.</p>
      </td>
    </tr>
  </table>

  <!-- Footer -->
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#001f3f;">
    <tr>
      <td style="padding:28px 40px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td>
              <p style="margin:0;color:#8899aa;font-size:12px;line-height:1.8;">
                &copy; {$year} UlendoPay Limited. All rights reserved.<br>
                <a href="mailto:support@ulendopay.com" style="color:#ff851b;text-decoration:none;">support@ulendopay.com</a>
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

</body>
</html>
HTML;

        Mail::html($html, function ($message) use ($user, $subject) {
            $message->to($user->email, $user->name)->subject($subject);
        });
    }

    public function verify(User $user, string $type, string $code): bool
    {
        $otp = OtpCode::where('user_id', $user->id)
            ->where('type', $type)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if (!$otp || !Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->update([
            'is_used' => true,
            'used_at' => now(),
        ]);

        return true;
    }
}
