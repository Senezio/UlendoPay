<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otpService) {}

    // ── Registration ─────────────────────────────────────────────────────────

    /**
     * Step 1 of registration — create account, send phone OTP.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'phone'        => 'required|string|max:20',
            'country_code' => 'required|string|size:3',
            'pin'          => 'required|string|size:4|confirmed|regex:/^\d{4}$/',
            'email'        => 'nullable|email|unique:users,email',
            'password'     => ['nullable', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        // Check phone not already registered via hash
        $phoneHash = hash('sha256', $data['phone']);
        $exists    = User::where('phone_hash', $phoneHash)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number is already registered.'],
            ]);
        }

        $user = User::create([
            'name'         => $data['name'],
            'email'        => $data['email'] ?? null,
            'password'     => isset($data['password']) && ($data['password'] ?? null) ? Hash::make($data['password']) : Hash::make(\Illuminate\Support\Str::random(32)),
            'country_code' => $data['country_code'],
            'kyc_status'   => 'none',
            'status'       => 'active',
        ]);

        // Use mutators for encrypted fields
        $user->phone = $data['phone'];
        $user->pin   = $data['pin'];
        $user->save();

        // Send phone verification OTP
        $this->otpService->send($user, 'phone_verification');

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'user.registered',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'new_values'  => ['country_code' => $user->country_code],
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Registration successful. Please verify your phone number.',
            'user_id' => $user->id,
            'next_step' => 'verify_phone',
        ], 201);
    }

    /**
     * Step 2 of registration — verify phone with OTP.
     */
    public function verifyPhone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'otp'     => 'required|string|size:6',
        ]);

        $this->throttle("verify_phone:{$data['user_id']}", 5, 10);

        $user = User::findOrFail($data['user_id']);

        if ($user->isPhoneVerified()) {
            return response()->json([
                'message' => 'Phone already verified.',
            ]);
        }

        if (!$this->otpService->verify($user, 'phone_verification', $data['otp'])) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired verification code.'],
            ]);
        }

        $user->update(['phone_verified_at' => now()]);

        // Create wallet for user's home currency
        $this->createUserWallet($user);

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'user.phone_verified',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return response()->json([
            'message'  => 'Phone verified successfully. You can now log in.',
            'verified' => true,
        ]);
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    /**
     * Step 1 of login — verify credentials, send 2FA OTP.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method'   => 'required|in:phone_pin,email_password',
            'phone'    => 'required_if:method,phone_pin|nullable|string',
            'pin'      => 'required_if:method,phone_pin|nullable|string|size:4',
            'email'    => 'required_if:method,email_password|nullable|email',
            'password' => 'required_if:method,email_password|nullable|string',
        ]);

        $throttleKey = "login:{$request->ip()}";
        $this->throttle($throttleKey, 5, 1);

        $user = match($data['method']) {
            'phone_pin'      => $this->authenticateByPhone($data),
            'email_password' => $this->authenticateByEmail($data),
        };

        if (!$user->isActive()) {
            throw ValidationException::withMessages([
                'account' => ['Your account has been suspended. Contact support.'],
            ]);
        }

        if (!$user->isPhoneVerified()) {
            // Resend verification OTP
            $this->otpService->send($user, 'phone_verification');

            return response()->json([
                'message'   => 'Please verify your phone number first.',
                'code'      => 'PHONE_NOT_VERIFIED',
                'user_id'   => $user->id,
                'next_step' => 'verify_phone',
            ], 403);
        }

        // Send 2FA OTP
        $this->otpService->send($user, 'login_2fa');

        RateLimiter::clear($throttleKey);

        return response()->json([
            'message'   => 'Verification code sent to your phone.',
            'user_id'   => $user->id,
            'next_step' => 'verify_2fa',
            'method'    => $data['method'],
        ]);
    }

    /**
     * Step 2 of login — verify 2FA OTP, issue token.
     */
    public function verifyLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'otp'     => 'required|string|size:6',
        ]);

        $this->throttle("verify_login:{$data['user_id']}", 5, 10);

        $user = User::findOrFail($data['user_id']);

        if (!$this->otpService->verify($user, 'login_2fa', $data['otp'])) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired verification code.'],
            ]);
        }

        // Revoke existing tokens — one active session at a time
        $user->tokens()->where('name', 'mobile')->delete();

        // Issue new token — 12 hour expiry for financial security
        $token = $user->createToken(
            'mobile',
            ['*'],
            now()->addHours(12)
        )->plainTextToken;

        $user->update([
            'last_login_at' => now(),
        ]);

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'user.login',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Login successful.',
            'token'   => $token,
            'expires_in' => 43200, // 12 hours in seconds
            'user'    => $this->formatUser($user),
        ]);
    }

    // ── Password / PIN Reset ──────────────────────────────────────────────────

    public function forgotPin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        $phoneHash = hash('sha256', $data['phone']);
        $user      = User::where('phone_hash', $phoneHash)->first();

        // Always return same response — never reveal if phone exists
        if ($user && $user->isActive() && $user->isPhoneVerified()) {
            $this->otpService->send($user, 'pin_reset');
        }

        return response()->json([
            'message' => 'If that phone number is registered, a reset code has been sent.',
        ]);
    }

    public function resetPin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'   => 'required|string|max:20',
            'otp'     => 'required|string|size:6',
            'pin'     => 'required|string|size:4|confirmed|regex:/^\d{4}$/',
        ]);

        $phoneHash = hash('sha256', $data['phone']);
        $user      = User::where('phone_hash', $phoneHash)
            ->where('status', 'active')
            ->firstOrFail();

        $this->throttle("reset_pin:{$user->id}", 3, 10);

        if (!$this->otpService->verify($user, 'pin_reset', $data['otp'])) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired reset code.'],
            ]);
        }

        $user->pin = $data['pin'];
        $user->save();

        // Revoke all tokens — force re-login after PIN reset
        $user->tokens()->delete();

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'user.pin_reset',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'PIN reset successfully. Please log in again.',
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);

        $user = User::where('email', $data['email'])->first();

        if ($user && $user->isActive()) {
            $this->otpService->send($user, 'password_reset');
        }

        return response()->json([
            'message' => 'If that email is registered, a reset code has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'otp'      => 'required|string|size:6',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user = User::where('email', $data['email'])
            ->where('status', 'active')
            ->firstOrFail();

        $this->throttle("reset_password:{$user->id}", 3, 10);

        if (!$this->otpService->verify($user, 'password_reset', $data['otp'])) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired reset code.'],
            ]);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        // Revoke all tokens — force re-login after password reset
        $user->tokens()->delete();

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'user.password_reset',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Password reset successfully. Please log in again.',
        ]);
    }

    // ── Session ───────────────────────────────────────────────────────────────

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'user.logout',
            'entity_type' => 'User',
            'entity_id'   => $request->user()->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->formatUser($request->user())]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function authenticateByPhone(array $data): User
    {
        $phoneHash = hash('sha256', $data['phone']);
        $user      = User::where('phone_hash', $phoneHash)->first();

        if (!$user || !$user->verifyPin($data['pin'])) {
            throw ValidationException::withMessages([
                'phone' => ['Invalid phone number or PIN.'],
            ]);
        }

        return $user;
    }

    private function authenticateByEmail(array $data): User
    {
        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        return $user;
    }

    private function throttle(string $key, int $maxAttempts, int $decayMinutes): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'throttle' => ["Too many attempts. Try again in {$seconds} seconds."],
            ]);
        }

        RateLimiter::hit($key, $decayMinutes * 60);
    }

    private function createUserWallet(User $user): void
    {
        // Map country to currency
        $currency = match(strtoupper($user->country_code)) {
            'MWI', 'MW' => 'MWK',
            'TZA', 'TZ' => 'TZS',
            'KEN', 'KE' => 'KES',
            'ZMB', 'ZM' => 'ZMW',
            'ZAF', 'ZA' => 'ZAR',
            'MOZ', 'MZ' => 'MZN',
            'BWA', 'BW' => 'BWP',
            'ETH', 'ET' => 'ETB',
            'MDG', 'MG' => 'MGA',
            default      => 'MWK',
        };

        // Create account
        $account = \App\Models\Account::create([
            'code'           => "USR-{$user->id}-{$currency}",
            'type'           => 'user_wallet',
            'currency_code'  => $currency,
            'owner_id'       => $user->id,
            'owner_type'     => User::class,
            'normal_balance' => 'credit',
            'is_active'      => true,
        ]);

        // Create wallet pointing to account
        \App\Models\Wallet::create([
            'user_id'       => $user->id,
            'account_id'    => $account->id,
            'currency_code' => $currency,
            'status'        => 'active',
        ]);

        // Create account balance starting at zero
        \App\Models\AccountBalance::create([
            'account_id'      => $account->id,
            'balance'         => 0,
            'currency_code'   => $currency,
            'last_updated_at' => now(),
        ]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'phone'            => $user->phone,
            'country_code'     => $user->country_code,
            'kyc_status'       => $user->kyc_status,
            'status'           => $user->status,
            'phone_verified'   => $user->isPhoneVerified(),
            'has_pin'          => !is_null($user->pin),
            'has_password'     => !is_null($user->password),
        ];
    }
}
