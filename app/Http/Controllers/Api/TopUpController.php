<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TopUp;
use App\Models\WebhookSignature;
use App\Services\TopUpService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TopUpController extends Controller
{
    public function __construct(private readonly TopUpService $topUpService) {}

    /**
     * Get supported operators for the authenticated user's currency.
     */
    public function operators(Request $request): JsonResponse
    {
        $user     = $request->user();
        $wallet   = $user->wallets()->where('status', 'active')->first();

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

    /**
     * Initiate a top-up — triggers USSD prompt on user's phone.
     */
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

    /**
     * Get top-up status by reference.
     * Frontend polls this after initiating to check if payment was confirmed.
     */
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

    /**
     * Get top-up history for authenticated user.
     */
    public function history(Request $request): JsonResponse
    {
        $topUps = TopUp::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json($topUps);
    }

    /**
     * Pawapay webhook — receives deposit status updates.
     *
     * This endpoint is PUBLIC — no auth middleware.
     * Security is via webhook signature verification.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload   = $request->all();
        $signature = $request->header('X-Pawapay-Signature') ?? '';

        Log::info('[TopUp Webhook] Received', [
            'payload'   => $payload,
            'signature' => substr($signature, 0, 20) . '...',
        ]);

        // Verify webhook signature
        if (!$this->verifyWebhookSignature($request->getContent(), $signature)) {
            Log::warning('[TopUp Webhook] Invalid signature', [
                'signature' => $signature,
            ]);

            // Return 200 anyway — Pawapay will retry on non-200
            // but we don't process unverified webhooks
            return response()->json(['message' => 'Signature verification failed.'], 200);
        }

        try {
            $this->topUpService->handleWebhook($payload);
        } catch (\Throwable $e) {
            Log::error('[TopUp Webhook] Processing failed', [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

        // Always return 200 to Pawapay — prevents unnecessary retries
        return response()->json(['message' => 'Webhook received.'], 200);
    }

    /**
     * Verify Pawapay webhook HMAC signature.
     */
    private function verifyWebhookSignature(string $body, string $signature): bool
    {
        // In sandbox mode — skip verification
        if (config('app.env') !== 'production') {
            return true;
        }

        $webhookSecret = WebhookSignature::whereHas('partner', fn($q) =>
            $q->where('code', 'PAWAPAY')->where('is_active', true)
        )->where('is_active', true)->first();

        if (!$webhookSecret) {
            Log::error('[TopUp Webhook] No active webhook signature configured for PAWAPAY');
            return false;
        }

        try {
            $secret   = decrypt($webhookSecret->secret_encrypted);
            $expected = hash_hmac('sha256', $body, $secret);
            return hash_equals($expected, $signature);
        } catch (\Throwable $e) {
            Log::error('[TopUp Webhook] Signature verification error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
