<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\AuthHelpers;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthSessionController extends Controller
{
    use AuthHelpers;

    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->currentAccessToken()->delete();

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'user.logout',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->formatUser($request->user())]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()->id;

        $tokens = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn($t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'last_used_at' => $t->last_used_at,
                'created_at'   => $t->created_at,
                'is_current'   => $t->id === $currentTokenId,
            ]);

        return response()->json(['sessions' => $tokens]);
    }

    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $token = $request->user()->tokens()->where('id', $tokenId)->first();

        if (!$token) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        if ($token->id === $request->user()->currentAccessToken()->id) {
            return response()->json(['message' => 'Cannot revoke your current session.'], 422);
        }

        $token->delete();

        return response()->json(['message' => 'Session revoked.']);
    }

    public function revokeAllSessions(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()->id;

        $request->user()->tokens()
            ->where('id', '!=', $currentTokenId)
            ->delete();

        return response()->json(['message' => 'All other sessions revoked.']);
    }

    public function verifyPin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pin' => 'required|string|size:4',
        ]);

        if (!$request->user()->verifyPin($data['pin'])) {
            throw ValidationException::withMessages([
                'pin' => ['Incorrect PIN. Please try again.'],
            ]);
        }

        return response()->json(['verified' => true]);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'otp'     => 'nullable|string|size:6',
            'send'    => 'nullable|boolean',
        ]);

        $user = User::findOrFail($data['user_id']);

        if (!empty($data['send'])) {
            if (empty($user->email)) {
                return response()->json(['message' => 'No email address on file.'], 422);
            }

            $this->otpService->send($user, 'email_verification', 'email');

            return response()->json(['message' => 'Verification code sent to your email.']);
        }

        if (!$this->otpService->verify($user, 'email_verification', $data['otp'])) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired verification code.'],
            ]);
        }

        $user->update(['email_verified_at' => now()]);

        return response()->json(['message' => 'Email verified successfully.']);
    }

    public function closeAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pin'    => 'required|string',
            'reason' => 'required|string|max:500',
        ]);

        $user = $request->user();

        if (!$user->verifyPin($data['pin'])) {
            return response()->json(['message' => 'Incorrect PIN.'], 422);
        }

        if ($user->status === 'suspended') {
            return response()->json([
                'message' => 'Your account has been suspended. Please contact support.',
            ], 422);
        }

        if ($user->status === 'closed') {
            return response()->json(['message' => 'Account is already closed.'], 422);
        }

        $service = app(\App\Services\AccountClosureService::class);

        $blockingReasons = $service->validate($user);
        if (!empty($blockingReasons)) {
            return response()->json([
                'message' => 'Account cannot be closed at this time.',
                'reasons' => $blockingReasons,
            ], 422);
        }

        try {
            $service->close($user, $data['reason'], $request->ip());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Your account has been permanently closed. All personal data has been anonymized.',
        ]);
    }

    public function auditLog(Request $request): JsonResponse
    {
        $logs = AuditLog::where('user_id', $request->user()->id)
            ->whereIn('action', [
                'login.success',
                'login.failed',
                'kyc.submitted',
                'kyc.approved',
                'kyc.rejected',
                'withdrawal.initiated',
                'topup.initiated',
                'pin.changed',
                'password.changed',
                '2fa.enabled',
                '2fa.disabled',
            ])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn($log) => [
                'action'     => $log->action,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
                'details'    => $log->new_values,
            ]);

        return response()->json(['logs' => $logs]);
    }
}
