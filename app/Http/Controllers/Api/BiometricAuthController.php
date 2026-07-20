<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\AuthHelpers;
use App\Models\BiometricDevice;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BiometricAuthController extends Controller
{
    use AuthHelpers;

    /**
     * Register a device public key after successful normal login.
     * POST /auth/biometric/register
     * { device_id, public_key, device_name, platform }
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id'   => 'required|string|max:128',
            'public_key'  => 'required|string|max:2048',
            'device_name' => 'nullable|string|max:255',
            'platform'    => 'nullable|string|in:android,ios',
        ]);

        BiometricDevice::updateOrCreate(
            ['device_id' => $data['device_id']],
            [
                'user_id'     => $request->user()->id,
                'public_key'  => $data['public_key'],
                'device_name' => $data['device_name'] ?? 'Mobile Device',
                'platform'    => $data['platform'] ?? 'android',
                'is_active'   => true,
            ]
        );

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'biometric.registered',
            'entity_type' => 'BiometricDevice',
            'entity_id'   => $request->user()->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Biometric login enabled for this device.']);
    }

    /**
     * Issue a short-lived challenge for the device to sign.
     * POST /auth/biometric/challenge
     * { device_id }
     */
    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => 'required|string|max:128',
        ]);

        $device = BiometricDevice::where('device_id', $data['device_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $challenge = Str::random(64);

        $device->update([
            'challenge'            => $challenge,
            'challenge_expires_at' => now()->addSeconds(30),
        ]);

        return response()->json(['challenge' => $challenge]);
    }

    /**
     * Verify the signed challenge and issue a token.
     * POST /auth/biometric/verify
     * { device_id, challenge, signature }
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => 'required|string|max:128',
            'challenge' => 'required|string',
            'signature' => 'required|string',
        ]);

        $device = BiometricDevice::where('device_id', $data['device_id'])
            ->where('is_active', true)
            ->with('user')
            ->firstOrFail();

        // Verify challenge matches and is not expired
        if ($device->challenge !== $data['challenge']) {
            throw ValidationException::withMessages([
                'challenge' => ['Invalid challenge.'],
            ]);
        }

        if ($device->challenge_expires_at === null || now()->isAfter($device->challenge_expires_at)) {
            throw ValidationException::withMessages([
                'challenge' => ['Challenge has expired. Please try again.'],
            ]);
        }

        // Verify ECDSA signature using the stored public key
        $publicKey = openssl_pkey_get_public($device->public_key);
        if (!$publicKey) {
            throw ValidationException::withMessages([
                'signature' => ['Invalid device public key.'],
            ]);
        }

        $signatureDecoded = base64_decode($data['signature']);
        $verified = openssl_verify(
            $data['challenge'],
            $signatureDecoded,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            AuditLog::create([
                'user_id'     => $device->user_id,
                'action'      => 'biometric.verify_failed',
                'entity_type' => 'BiometricDevice',
                'entity_id'   => $device->id,
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
            ]);

            throw ValidationException::withMessages([
                'signature' => ['Biometric verification failed.'],
            ]);
        }

        // Invalidate used challenge
        $device->update([
            'challenge'            => null,
            'challenge_expires_at' => null,
            'last_used_at'         => now(),
        ]);

        $user = $device->user;

        if (!$user->isActive()) {
            throw ValidationException::withMessages([
                'account' => ['Your account has been suspended.'],
            ]);
        }

        // Issue a new token for this device without touching tokens from
        // other devices, so biometric login on one phone doesn't sign out a
        // session on another. Device name/platform reuse what was captured
        // at biometric registration time for this device_id, rather than
        // requiring the client to send it again here.
        $tokenResult = $user->createToken('mobile', ['*'], now()->addHours(12));
        $tokenResult->accessToken->forceFill([
            'device_name' => $device->device_name,
            'platform'    => $device->platform,
            'user_agent'  => $request->userAgent(),
            'ip_address'  => $request->ip(),
        ])->save();
        $token = $tokenResult->plainTextToken;

        $user->update(['last_login_at' => now()]);

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'biometric.login',
            'entity_type' => 'BiometricDevice',
            'entity_id'   => $device->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return response()->json([
            'message'    => 'Biometric login successful.',
            'token'      => $token,
            'expires_in' => 43200,
            'user'       => $this->formatUser($user),
        ]);
    }

    /**
     * Revoke biometric login for this device.
     * DELETE /auth/biometric/device
     * { device_id }
     */
    public function revoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => 'required|string|max:128',
        ]);

        BiometricDevice::where('device_id', $data['device_id'])
            ->where('user_id', $request->user()->id)
            ->update(['is_active' => false]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'biometric.revoked',
            'entity_type' => 'BiometricDevice',
            'entity_id'   => $request->user()->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Biometric login disabled for this device.']);
    }
}
