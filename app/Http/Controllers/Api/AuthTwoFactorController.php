<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthTwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorAuthService $twoFactor,
    ) {}

    public function setup(Request $request): JsonResponse
    {
        $result = $this->twoFactor->setup($request->user());

        return response()->json([
            'secret'         => $result['secret'],
            'qr_code_url'    => $result['qr_code_url'],
            'recovery_codes' => $result['recovery_codes'],
        ]);
    }

    public function enable(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string']);

        if (!$this->twoFactor->verify($request->user(), $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['Invalid verification code.'],
            ]);
        }

        return response()->json(['message' => 'Two-factor authentication enabled successfully.']);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string']);

        if (!$this->twoFactor->verify($request->user(), $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['Invalid verification code.'],
            ]);
        }

        $this->twoFactor->disable($request->user());

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    public function status(Request $request): JsonResponse
    {
        $twoFactor = $request->user()->twoFactorAuth;

        return response()->json([
            'is_enabled'   => $twoFactor?->is_enabled ?? false,
            'enabled_at'   => $twoFactor?->enabled_at,
            'last_used_at' => $twoFactor?->last_used_at,
        ]);
    }
}
