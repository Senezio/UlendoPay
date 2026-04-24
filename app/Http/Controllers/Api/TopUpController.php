<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\VerifiesWebhookSignature;
use App\Models\TopUp;
use App\Services\TopUpService;
use App\Services\MtnMomoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TopUpController extends Controller
{
    use VerifiesWebhookSignature;

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
     * Secured via RFC-9421 ECDSA P-256 SHA-256 signature.
     * See VerifiesWebhookSignature trait for verification logic.
     */
    public function pawapayWebhook(Request $request): JsonResponse
    {
        Log::info('[TopUp][PawaPay] Webhook received', [
            'all_headers' => $request->headers->all(),
        ]);

        if (!$this->verifyPawapaySignature($request)) {
            Log::warning('[TopUp][PawaPay] Invalid signature — webhook rejected');
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

        $mtnReference = $payload['referenceId']
            ?? $payload['externalId']
            ?? null;

        if (!$mtnReference) {
            Log::warning('[TopUp][MTN] Webhook missing referenceId/externalId');
            return response()->json(['message' => 'Invalid payload.'], 200);
        }

        try {
            // Verify by calling back to MTN — never trust the payload alone
            $mtnMomo         = new MtnMomoService();
            $verifiedStatus  = $mtnMomo->getTopUpStatus($mtnReference);
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
            $verifiedPayload              = $payload;
            $verifiedPayload['status']    = $confirmedStatus;
            $verifiedPayload['depositId'] = $mtnReference;

            $this->topUpService->handleWebhook($verifiedPayload);

        } catch (\Throwable $e) {
            Log::error('[TopUp][MTN] Webhook processing failed', [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

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
}
