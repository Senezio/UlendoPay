<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class PublicController extends Controller
{
    /**
     * Handle contact form submission.
     * Sends an email to support@ulendopay.com.
     */
    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        try {
            $name    = $data['name'];
            $email   = $data['email'];
            $message = nl2br(e($data['message']));
            $year    = date('Y');
            $time    = now()->format('D, d M Y H:i T');
            $ip      = $request->ip();

            $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>New Contact Form Submission</title>
</head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

  <!-- Top navy bar with logo -->
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#001f3f;">
    <tr>
      <td style="padding:24px 40px;">
        <table cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="vertical-align:middle;padding-right:14px;">
              <img src="https://ulendopay.com/logo.png" alt="UlendoPay" height="32" style="display:block;">
            </td>
            <td style="vertical-align:middle;border-left:1px solid #1e3a5f;padding-left:14px;">
              <span style="color:#8899aa;font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;">Contact Form</span>
            </td>
          </tr>
        </table>
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
        <p style="margin:0 0 4px;color:#ff851b;font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;">UlendoPay Support</p>
        <h1 style="margin:0 0 32px;color:#001f3f;font-size:26px;font-weight:800;line-height:1.2;">New Message from Website</h1>

        <!-- Divider -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
          <tr><td style="border-top:1px solid #e8edf2;font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>

        <!-- Sender -->
        <p style="margin:0 0 6px;color:#a0aec0;font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;">From</p>
        <p style="margin:0 0 4px;color:#001f3f;font-size:16px;font-weight:700;">{$name}</p>
        <p style="margin:0 0 32px;"><a href="mailto:{$email}" style="color:#ff851b;text-decoration:none;font-size:14px;">{$email}</a></p>

        <!-- Divider -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
          <tr><td style="border-top:1px solid #e8edf2;font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>

        <!-- Message -->
        <p style="margin:0 0 12px;color:#a0aec0;font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;">Message</p>
        <p style="margin:0 0 32px;color:#4a5568;font-size:15px;line-height:1.8;">{$message}</p>

        <!-- Divider -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
          <tr><td style="border-top:1px solid #e8edf2;font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>

        <!-- Meta -->
        <p style="margin:0 0 4px;color:#a0aec0;font-size:12px;">Submitted: {$time}</p>
        <p style="margin:0 0 48px;color:#a0aec0;font-size:12px;">IP Address: {$ip}</p>
      </td>
    </tr>
  </table>

  <!-- Footer -->
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#001f3f;">
    <tr>
      <td style="padding:28px 40px;">
        <p style="margin:0;color:#8899aa;font-size:12px;line-height:1.6;">
          &copy; {$year} UlendoPay Limited. All rights reserved.<br>
          Reply directly to this email to respond to {$name}.
        </p>
      </td>
    </tr>
  </table>

</body>
</html>
HTML;

            Mail::html($html, function ($mail) use ($name, $email) {
                $mail->to('support@ulendopay.com', 'UlendoPay Support')
                     ->replyTo($email, $name)
                     ->subject("Contact: New message from {$name}");
            });

            Log::info('[Contact] Form submission received', [
                'name'  => $name,
                'email' => $email,
                'ip'    => $ip,
            ]);

            return response()->json([
                'message' => 'Your message has been sent. We will get back to you within 24 hours.',
            ]);

        } catch (\Throwable $e) {
            Log::error('[Contact] Failed to send contact email', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send your message. Please try again or email us directly at support@ulendopay.com.',
            ], 500);
        }
    }
}
