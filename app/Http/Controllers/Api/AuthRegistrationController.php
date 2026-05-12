<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\AuthHelpers;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OtpService;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthRegistrationController extends Controller
{
    use AuthHelpers;

    public function __construct(
        private readonly OtpService          $otpService,
        private readonly RegistrationService $registrationService,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255', 'regex:/^[\pL\s\'\-\.]+$/u'],
            'phone'         => 'required|string|max:20',
            'country_code'  => 'required|string|size:3',
            'referral_code' => 'nullable|string|max:10',
            'pin'           => 'required|string|size:4|confirmed|regex:/^\d{4}$/',
            'email'         => 'nullable|email|unique:users,email',
            'password'      => ['nullable', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $phoneHash = hash('sha256', $data['phone']);

        if (User::where('phone_hash', $phoneHash)->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number is already registered.'],
            ]);
        }

        $user = new User([
            'name'         => $data['name'],
            'email'        => $data['email'] ?? null,
            'password'     => isset($data['password']) && $data['password']
                ? Hash::make($data['password'])
                : Hash::make(Str::random(32)),
            'country_code' => $data['country_code'],
            'kyc_status'   => 'none',
            'status'       => 'active',
        ]);

        $user->phone = $data['phone'];
        $user->pin   = $data['pin'];
        $user->save();

        if (app()->environment('local')) {
            $user->update(['phone_verified_at' => now()]);
            $this->registrationService->createUserWallet($user, $data['referral_code'] ?? null);

            return response()->json([
                'message'   => 'Registration successful (Local Bypass).',
                'user'      => $this->formatUser($user),
                'token'     => $user->createToken('auth_token')->plainTextToken,
                'next_step' => 'dashboard',
            ], 201);
        }

        $this->otpService->send($user, 'phone_verification');

        if ($user->email) {
            $this->otpService->send($user, 'phone_verification', 'email');
        }

        return response()->json([
            'message'   => 'Registration successful. Please verify your phone number.',
            'user_id'   => $user->id,
            'next_step' => 'verify_phone',
        ], 201);
    }

    public function verifyPhone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'       => 'required|integer|exists:users,id',
            'otp'           => 'required|string|size:6',
            'referral_code' => 'nullable|string|max:10',
        ]);

        $this->throttle("verify_phone:{$data['user_id']}", 5, 10);

        $user = User::findOrFail($data['user_id']);

        if ($user->isPhoneVerified()) {
            return response()->json(['message' => 'Phone already verified.']);
        }

        if (!$this->otpService->verify($user, 'phone_verification', $data['otp'])) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired verification code.'],
            ]);
        }

        $user->update(['phone_verified_at' => now()]);

        $this->registrationService->createUserWallet($user, $data['referral_code'] ?? null);
        $this->registrationService->releasePendingClaims($user);

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

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'type'    => 'required|in:login_2fa,phone_verification',
        ]);

        $this->throttle("resend_otp:{$data['user_id']}", 3, 10);

        $user    = User::findOrFail($data['user_id']);
        $channel = $user->email ? 'email' : 'sms';

        $this->otpService->send($user, $data['type'], $channel);

        return response()->json([
            'message' => 'Verification code resent via ' . $channel . '.',
            'channel' => $channel,
        ]);
    }
}
