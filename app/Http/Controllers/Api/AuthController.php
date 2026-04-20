<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditLog;
use App\Models\PendingClaim;
use App\Models\Account;
use App\Models\OutboxEvent;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use App\Services\RateLimiterService;
use App\Services\TwoFactorAuthService;
use App\Services\TierService;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly RateLimiterService $rateLimiter,
        private readonly TwoFactorAuthService $twoFactor,
    ) {}

    // ── Registration ─────────────────────────────────────────────────────────

    /**
     * Step 1 of registration — create account, send phone OTP.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255', 'regex:/^[\pL\s\'\-\.]+$/u'],
            'phone'        => 'required|string|max:20',
            'country_code'  => 'required|string|size:3',
            'referral_code' => 'nullable|string|max:10',
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
        if (app()->environment('local')) {
            // Local: Auto-verify and jump to dashboard
            $user->update(['phone_verified_at' => now()]);
            $this->createUserWallet($user);

            return response()->json([
                'message'   => 'Registration successful (Local Bypass).',
                'user'      => $user,
                'token'     => $user->createToken('auth_token')->plainTextToken,
                'next_step' => 'dashboard',
            ], 201);
        }

        // Production: Send OTP and require manual verification
        $this->otpService->send($user, 'phone_verification');

        return response()->json([
            'message'   => 'Registration successful. Please verify your phone number.',
            'user_id'   => $user->id,
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

        // Release any pending claims for this phone number
        $this->releasePendingClaims($user);

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
            $this->otpService->send($user, 'phone_verification');

            return response()->json([
                'message'   => 'Please verify your phone number first.',
                'code'      => 'PHONE_NOT_VERIFIED',
                'user_id'   => $user->id,
                'next_step' => 'verify_phone',
            ], 403);
        }

        // Check environment: skip 2FA if local
        if (app()->environment('local')) {
            auth()->login($user);
            RateLimiter::clear($throttleKey);
            $this->rateLimiter->clear($throttleKey, 'login');

            return response()->json([
                'message'   => 'Login successful (Local Environment).',
                'user'      => $user,
                'token'     => $user->createToken('auth_token')->plainTextToken,
                'next_step' => 'dashboard',
            ]);
        }

        // Check if user has TOTP enabled — use that instead of SMS
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

        // Send SMS OTP for users without TOTP
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

    /**
     * Step 2 of login — verify TOTP code, issue token.
     */
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

        auth()->login($user);

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $user,
            'token'   => $user->createToken('auth_token')->plainTextToken,
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
            $this->otpService->send($user, 'password_reset', 'email');

            // Also store in password_reset_tokens for standard compatibility
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

        // Revoke all tokens — force re-login after password reset
        $user->tokens()->delete();

        // Clear password reset token
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

    // ── Two-Factor Authentication ─────────────────────────────────────────────

    public function accountNumbers(Request $request): JsonResponse
    {
        $user = $request->user();

        $accounts = \App\Models\Account::where('owner_id', $user->id)
            ->where('owner_type', \App\Models\User::class)
            ->where('type', 'user_wallet')
            ->where('is_active', true)
            ->get(['code', 'currency_code']);

        return response()->json([
            'accounts' => $accounts->map(fn($a) => [
                'account_number' => $a->code,
                'currency'       => $a->currency_code,
            ])
        ]);
    }

    public function twoFactorSetup(Request $request): JsonResponse
    {
        $result = $this->twoFactor->setup($request->user());
        return response()->json([
            'secret'         => $result['secret'],
            'qr_code_url'    => $result['qr_code_url'],
            'recovery_codes' => $result['recovery_codes'],
        ]);
    }

    public function twoFactorEnable(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string']);

        if (!$this->twoFactor->verify($request->user(), $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['Invalid verification code.'],
            ]);
        }

        return response()->json(['message' => 'Two-factor authentication enabled successfully.']);
    }

    public function twoFactorDisable(Request $request): JsonResponse
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

    public function twoFactorStatus(Request $request): JsonResponse
    {
        $user      = $request->user();
        $twoFactor = $user->twoFactorAuth;
        return response()->json([
            'is_enabled'   => $twoFactor?->is_enabled ?? false,
            'enabled_at'   => $twoFactor?->enabled_at,
            'last_used_at' => $twoFactor?->last_used_at,
        ]);
    }

    private function throttle(string $key, int $maxAttempts, int $decayMinutes): void
    {
        // Also attempt DB-backed rate limiting
        $action = explode(':', $key)[0];
        $this->rateLimiter->attempt($key, $action);

        // Fallback to Laravel facade for fine-grained control
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

        // Apply referral if provided
        if (!empty($data['referral_code'])) {
            app(TierService::class)->applyReferral($user, $data['referral_code']);
        }

        // Generate referral code for new user
        app(TierService::class)->generateReferralCode($user);

        // Create account
        $account = \App\Models\Account::create([
            'code'           => (function() { do { $c = (string)random_int(1,9).str_pad(random_int(0,99999999),8,'0',STR_PAD_LEFT).random_int(1,9); } while(\App\Models\Account::where('code',$c)->exists()); return $c; })(),
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
            'is_staff'         => (bool) $user->is_staff,
            'role'             => $user->role,
            'phone_verified'   => $user->isPhoneVerified(),
            'has_pin'          => !is_null($user->pin),
            'has_password'     => !is_null($user->password),
        ];
    }
    /**
     * Release any pending claims for a newly verified user.
     * Called after phone verification completes.
     */
    private function releasePendingClaims(User $user): void
    {
        $phoneHash = hash('sha256', $user->phone);

        $claims = PendingClaim::where('recipient_phone_hash', $phoneHash)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->get();

        if ($claims->isEmpty()) return;

        foreach ($claims as $claim) {
            try {
                DB::transaction(function () use ($claim, $user) {
                    $ledger = app(LedgerService::class);

                    // Find or create recipient wallet
                    $recipientAccount = Account::where('owner_id', $user->id)
                        ->where('owner_type', User::class)
                        ->where('type', 'user_wallet')
                        ->where('currency_code', $claim->currency_code)
                        ->first();

                    if (!$recipientAccount) return;

                    $escrowAccount = Account::where('type', 'escrow')
                        ->where('currency_code', $claim->currency_code)
                        ->firstOrFail();

                    $reference = $claim->transaction->reference_number;

                    $ledger->post(
                        reference:   "TXN-{$reference}-CLAIM",
                        type:        'transfer_claim',
                        currency:    $claim->currency_code,
                        entries: [
                            [
                                'account_id'  => $escrowAccount->id,
                                'type'        => 'debit',
                                'amount'      => $claim->amount,
                                'description' => "Claim released: {$reference}",
                            ],
                            [
                                'account_id'  => $recipientAccount->id,
                                'type'        => 'credit',
                                'amount'      => $claim->amount,
                                'description' => "Claimed transfer: {$reference}",
                            ],
                        ],
                        description: "Claim release for {$reference}"
                    );

                    $claim->update([
                        'status'     => 'claimed',
                        'claimed_by' => $user->id,
                        'claimed_at' => now(),
                    ]);

                    $claim->transaction->update([
                        'status'       => 'completed',
                        'completed_at' => now(),
                    ]);

                    // Notify recipient
                    OutboxEvent::create([
                        'event_type'     => 'sms_notification',
                        'transaction_id' => $claim->transaction_id,
                        'payload'        => [
                            'type'      => 'claim_released',
                            'phone'     => $user->phone,
                            'amount'    => $claim->amount,
                            'currency'  => $claim->currency_code,
                            'reference' => $reference,
                        ],
                        'status'          => 'pending',
                        'next_attempt_at' => now(),
                    ]);
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[Auth] Failed to release pending claim', [
                    'claim_id' => $claim->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }


    public function verifyPin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pin' => 'required|string|size:4',
        ]);

        if (!$request->user()->verifyPin($data['pin'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pin' => ['Incorrect PIN. Please try again.'],
            ]);
        }

        return response()->json(['verified' => true]);
    }


    /**
     * List active sessions for the authenticated user.
     */
    public function sessions(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn($t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'last_used_at' => $t->last_used_at,
                'created_at'   => $t->created_at,
                'is_current'   => $t->id === $request->user()->currentAccessToken()->id,
            ]);

        return response()->json(['sessions' => $tokens]);
    }

    /**
     * Revoke a specific session token.
     */
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

    /**
     * Revoke all other sessions except current.
     */
    public function revokeAllSessions(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()->id;

        $request->user()->tokens()
            ->where('id', '!=', $currentTokenId)
            ->delete();

        return response()->json(['message' => 'All other sessions revoked.']);
    }

    /**
     * Get audit log for the authenticated user.
     */
    public function auditLog(Request $request): JsonResponse
    {
        $logs = \App\Models\AuditLog::where('user_id', $request->user()->id)
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

    /**
     * Verify email OTP during registration.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'otp'     => 'nullable|string|size:6',
            'send'    => 'nullable|boolean',
        ]);

        $user = \App\Models\User::findOrFail($data['user_id']);

        // Just send the OTP
        if (!empty($data['send'])) {
            if (empty($user->email)) {
                return response()->json(['message' => 'No email address on file.'], 422);
            }
            $this->otpService->send($user, 'email_verification', 'email');
            return response()->json(['message' => 'Verification code sent to your email.']);
        }

        // Verify the OTP
        if (!$this->otpService->verify($user, 'email_verification', $data['otp'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'otp' => ['Invalid or expired verification code.'],
            ]);
        }

        $user->update(['email_verified_at' => now()]);

        return response()->json(['message' => 'Email verified successfully.']);
    }

}