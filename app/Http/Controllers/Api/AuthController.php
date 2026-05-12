<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\AuthHelpers;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OtpService;
use App\Services\RateLimiterService;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use AuthHelpers;

    public function __construct(
        private readonly OtpService           $otpService,
        private readonly RateLimiterService   $rateLimiter,
        private readonly TwoFactorAuthService $twoFactor,
    ) {}

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
            $this->otpService->send($user, 'phone_verification');

            return response()->json([
                'message'   => 'Please verify your phone number first.',
                'code'      => 'PHONE_NOT_VERIFIED',
                'user_id'   => $user->id,
                'next_step' => 'verify_phone',
            ], 403);
        }

        if (app()->environment('local')) {
            RateLimiter::clear($throttleKey);
            $this->rateLimiter->clear($throttleKey, 'login');

            return response()->json([
                'message'   => 'Login successful (Local Environment).',
                'user'      => $this->formatUser($user),
                'token'     => $user->createToken('auth_token')->plainTextToken,
                'next_step' => 'dashboard',
            ]);
        }

        if ($this->twoFactor->isEnabled($user)) {
            RateLimiter::clear($throttleKey);
            $this->rateLimiter->clear($throttleKey, 'login');

            return response()->json([
                'message'   => 'Enter the code from your authenticator app.',
                'user_id'   => $user->id,
                'next_step' => 'verify_totp',
                'method'    => $data['method'],
            ]);
        }

        $this->otpService->send($user, 'login_2fa');

        RateLimiter::clear($throttleKey);
        $this->rateLimiter->clear($throttleKey, 'login');

        return response()->json([
            'message'   => 'Verification code sent to your phone.',
            'user_id'   => $user->id,
            'next_step' => 'verify_2fa',
            'method'    => $data['method'],
        ]);
    }

    public function verifyTotp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'code'    => 'required|string',
        ]);

        $this->throttle("verify_totp:{$data['user_id']}", 5, 10);

        $user = User::findOrFail($data['user_id']);

        if (!$this->twoFactor->verify($user, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired authenticator code.'],
            ]);
        }

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $this->formatUser($user),
            'token'   => $user->createToken('auth_token')->plainTextToken,
        ]);
    }

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

        $user->tokens()->where('name', 'mobile')->delete();

        $token = $user->createToken(
            'mobile',
            ['*'],
            now()->addHours(12)
        )->plainTextToken;

        $user->update(['last_login_at' => now()]);

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'user.login',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return response()->json([
            'message'    => 'Login successful.',
            'token'      => $token,
            'expires_in' => 43200,
            'user'       => $this->formatUser($user),
        ]);
    }

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
}
