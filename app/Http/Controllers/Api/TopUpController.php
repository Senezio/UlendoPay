<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TopUp;
use App\Models\WebhookSignature;
use App\Services\TopUpService;
use App\Services\MtnMomoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TopUpController extends Controller
{
    public function __construct(private readonly TopUpService $topUpService) {}

    public function operators(Request $request): JsonResponse
    {
        $user   = $request->user();
        $wallet = $user->wallets()->where('status', 'active')->first();

        if (!$wallet) {
            return response()->json([
                'message' => 'No active wallet found.',
                'code'    => 'NO_WALLET',
            ], 422);
        }

        $operators = $this->topUpService->getSupportedOperators($wallet->currency_code);

        return response()->json([
            'currency'  => $wallet->currency_code,
            'operators' => $operators,
        ]);
    }

    public function initiate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone_number'    => 'required|string|max:20',
            'mobile_operator' => 'required|string|max:50',
            'amount'          => 'required|numeric|min:1',
        ]);

        try {
            $topUp = $this->topUpService->initiate(
                user:           $request->user(),
                phoneNumber:    $data['phone_number'],
                mobileOperator: strtoupper($data['mobile_operator']),
                amount:         (float) $data['amount'],
            );

            return response()->json([
                'message'   => 'Payment prompt sent to your phone. Please approve the transaction.',
                'reference' => $topUp->reference,
                'status'    => $topUp->status,
                'amount'    => $topUp->amount,
                'currency'  => $topUp->currency_code,
            ], 201);

        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'TOPUP_FAILED',
            ], 422);
        }
    }

    public function status(Request $request, string $reference): JsonResponse
    {
        $topUp = TopUp::where('reference', $reference)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'reference'    => $topUp->reference,
            'status'       => $topUp->status,
            'amount'       => $topUp->amount,
            'currency'     => $topUp->currency_code,
            'initiated_at' => $topUp->initiated_at,
            'completed_at' => $topUp->completed_at,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $topUps = TopUp::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json($topUps);
    }

    /**
     * PawaPay deposit webhook.
     * Secured via HMAC-SHA256 signature on raw request body.
     * Header: X-Pawapay-Signature
     */
    public function pawapayWebhook(Request $request): JsonResponse
    {
        $signature = $request->header('X-Pawapay-Signature') ?? '';

        Log::info('[TopUp][PawaPay] Webhook received', [
            'signature' => substr($signature, 0, 20) . '...',
        ]);

        if (!$this->verifyPawapaySignature($request->getContent(), $signature)) {
            Log::warning('[TopUp][PawaPay] Invalid signature — webhook rejected');
            // Return 200 to prevent PawaPay retrying a legitimately rejected webhook
            return response()->json(['message' => 'Signature verification failed.'], 200);
        }

        try {
            $this->topUpService->handleWebhook($request->all());
        } catch (\Throwable $e) {
            Log::error('[TopUp][PawaPay] Webhook processing failed', [
                'error'   => $e->getMessage(),
                'payload' => $request->all(),
            ]);
        }

        return response()->json(['message' => 'Webhook received.'], 200);
    }

    /**
     * MTN MoMo collection webhook.
     * MTN does not use HMAC signatures — verification is done by calling
     * back to MTN's status API to confirm the transaction actually completed.
     * This prevents replay attacks and spoofed webhooks.
     */
    public function mtnWebhook(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('[TopUp][MTN] Webhook received', ['payload' => $payload]);

        // MTN sends externalId as our reference, and a referenceId as their UUID
        $mtnReference = $payload['referenceId']
            ?? $payload['externalId']
            ?? null;

        if (!$mtnReference) {
            Log::warning('[TopUp][MTN] Webhook missing referenceId/externalId');
            return response()->json(['message' => 'Invalid payload.'], 200);
        }

        try {
            // Verify by calling back to MTN — never trust the payload alone
            $mtnMomo       = new MtnMomoService();
            $verifiedStatus = $mtnMomo->getTopUpStatus($mtnReference);
            $confirmedStatus = $verifiedStatus['status'] ?? null;

            Log::info('[TopUp][MTN] Status verified', [
                'mtn_reference'    => $mtnReference,
                'confirmed_status' => $confirmedStatus,
            ]);

            if (!$confirmedStatus) {
                Log::error('[TopUp][MTN] Could not verify status from MTN API');
                return response()->json(['message' => 'Verification failed.'], 200);
            }

            // Replace payload status with verified status from MTN API
            $verifiedPayload             = $payload;
            $verifiedPayload['status']   = $confirmedStatus;
            $verifiedPayload['depositId'] = $mtnReference;

            $this->topUpService->handleWebhook($verifiedPayload);

        } catch (\Throwable $e) {
            Log::error('[TopUp][MTN] Webhook processing failed', [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

        // Always return 200 — MTN will retry on non-200
        return response()->json(['message' => 'Webhook received.'], 200);
    }

    /**
     * Legacy shared webhook — kept for backward compatibility with simulator.
     * Routes to PawaPay handler. Remove after simulator is updated.
     */
    public function webhook(Request $request): JsonResponse
    {
        return $this->pawapayWebhook($request);
    }

    private function verifyPawapaySignature(string $body, string $signature): bool
    {
        // Always pass in non-production — enables simulator testing
        if (config('app.env') !== 'production') {
            // But still attempt verification if a secret is configured
            // so we can catch signature bugs before going live
            $webhookSecret = WebhookSignature::whereHas('partner', fn($q) =>
                $q->where('code', 'PAWAPAY')->where('is_active', true)
            )->where('is_active', true)->first();

            if (!$webhookSecret) {
                Log::info('[TopUp][PawaPay] No webhook secret configured — skipping verification in non-production');
                return true;
            }

            try {
                $secret   = decrypt($webhookSecret->secret_encrypted);
                $expected = hash_hmac('sha256', $body, $secret);
                $valid    = hash_equals($expected, $signature);

                if (!$valid) {
                    Log::warning('[TopUp][PawaPay] Signature mismatch in non-production — continuing anyway', [
                        'expected' => substr($expected, 0, 20) . '...',
                        'received' => substr($signature, 0, 20) . '...',
                    ]);
                }

                return true; // Non-production always passes
            } catch (\Throwable $e) {
                Log::warning('[TopUp][PawaPay] Signature check error in non-production', [
                    'error' => $e->getMessage(),
                ]);
                return true;
            }
        }

        // Production — strict verification required
        $webhookSecret = WebhookSignature::whereHas('partner', fn($q) =>
            $q->where('code', 'PAWAPAY')->where('is_active', true)
        )->where('is_active', true)->first();

        if (!$webhookSecret) {
            Log::error('[TopUp][PawaPay] No active webhook signature configured for PAWAPAY');
            return false;
        }

        try {
            $secret   = decrypt($webhookSecret->secret_encrypted);
            $expected = hash_hmac('sha256', $body, $secret);
            return hash_equals($expected, $signature);
        } catch (\Throwable $e) {
            Log::error('[TopUp][PawaPay] Signature verification error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
