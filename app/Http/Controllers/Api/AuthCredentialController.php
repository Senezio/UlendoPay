<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\AuthHelpers;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthCredentialController extends Controller
{
    use AuthHelpers;

    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    public function forgotPin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        $phoneHash = hash('sha256', $data['phone']);
        $user      = User::where('phone_hash', $phoneHash)->first();

        if ($user && $user->isActive() && $user->isPhoneVerified()) {
            $user->email
                ? $this->otpService->send($user, 'pin_reset', 'email')
                : $this->otpService->send($user, 'pin_reset');
        }

        return response()->json([
            'message' => 'If that phone number is registered, a reset code has been sent.',
        ]);
    }

    public function resetPin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'otp'   => 'required|string|size:6',
            'pin'   => 'required|string|size:4|confirmed|regex:/^\d{4}$/',
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
            $this->otpService->send($user, 'password_reset', 'email');

            DB::table('password_reset_tokens')->upsert([
                'email'      => $user->email,
                'token'      => Hash::make($user->email . now()->timestamp),
                'created_at' => now(),
            ], ['email']);
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
        $user->tokens()->delete();

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

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
}
