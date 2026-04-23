<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\WebhookSignature;
use App\Services\WithdrawalService;
use App\Services\MtnMomoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawalService) {}

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

        $operators = $this->withdrawalService->getSupportedOperators($wallet->currency_code);

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
            $withdrawal = $this->withdrawalService->initiate(
                user:           $request->user(),
                phoneNumber:    $data['phone_number'],
                mobileOperator: strtoupper($data['mobile_operator']),
                amount:         (float) $data['amount'],
            );

            return response()->json([
                'message'   => 'Withdrawal initiated. Funds will be sent to your mobile money wallet.',
                'reference' => $withdrawal->reference,
                'status'    => $withdrawal->status,
                'amount'    => $withdrawal->amount,
                'currency'  => $withdrawal->currency_code,
            ], 201);

        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'WITHDRAWAL_FAILED',
            ], 422);
        }
    }

    public function status(Request $request, string $reference): JsonResponse
    {
        $withdrawal = Withdrawal::where('reference', $reference)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'reference'      => $withdrawal->reference,
            'status'         => $withdrawal->status,
            'amount'         => $withdrawal->amount,
            'currency'       => $withdrawal->currency_code,
            'initiated_at'   => $withdrawal->initiated_at,
            'completed_at'   => $withdrawal->completed_at,
            'failure_reason' => $withdrawal->failure_reason,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $withdrawals = Withdrawal::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json($withdrawals);
    }

    /**
     * PawaPay payout webhook.
     * Secured via HMAC-SHA256 signature on raw request body.
     * Header: X-Pawapay-Signature
     */
    public function pawapayWebhook(Request $request): JsonResponse
    {
        $signature = $request->header('X-Pawapay-Signature') ?? '';

        Log::info('[Withdrawal][PawaPay] Webhook received', [
            'signature' => substr($signature, 0, 20) . '...',
        ]);

        if (!$this->verifyPawapaySignature($request->getContent(), $signature)) {
            Log::warning('[Withdrawal][PawaPay] Invalid signature — webhook rejected');
            return response()->json(['message' => 'Signature verification failed.'], 200);
        }

        try {
            $this->withdrawalService->handleWebhook($request->all());
        } catch (\Throwable $e) {
            Log::error('[Withdrawal][PawaPay] Webhook processing failed', [
                'error'   => $e->getMessage(),
                'payload' => $request->all(),
            ]);
        }

        return response()->json(['message' => 'Webhook received.'], 200);
    }

    /**
     * MTN MoMo disbursement webhook.
     * MTN does not use HMAC signatures — verification is done by calling
     * back to MTN's status API to confirm the transfer actually completed.
     */
    public function mtnWebhook(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('[Withdrawal][MTN] Webhook received', ['payload' => $payload]);

        $mtnReference = $payload['referenceId']
            ?? $payload['externalId']
            ?? null;

        if (!$mtnReference) {
            Log::warning('[Withdrawal][MTN] Webhook missing referenceId/externalId');
            return response()->json(['message' => 'Invalid payload.'], 200);
        }

        try {
            // Verify by calling back to MTN — never trust the payload alone
            $mtnMomo        = new MtnMomoService();
            $verifiedStatus = $mtnMomo->getWithdrawalStatus($mtnReference);
            $confirmedStatus = $verifiedStatus['status'] ?? null;

            Log::info('[Withdrawal][MTN] Status verified', [
                'mtn_reference'    => $mtnReference,
                'confirmed_status' => $confirmedStatus,
            ]);

            if (!$confirmedStatus) {
                Log::error('[Withdrawal][MTN] Could not verify status from MTN API');
                return response()->json(['message' => 'Verification failed.'], 200);
            }

            // Replace payload status with verified status from MTN API
            $verifiedPayload           = $payload;
            $verifiedPayload['status'] = $confirmedStatus;
            $verifiedPayload['payoutId'] = $mtnReference;

            $this->withdrawalService->handleWebhook($verifiedPayload);

        } catch (\Throwable $e) {
            Log::error('[Withdrawal][MTN] Webhook processing failed', [
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

    private function verifyPawapaySignature(string $body, string $signature): bool
    {
        if (config('app.env') !== 'production') {
            $webhookSecret = WebhookSignature::whereHas('partner', fn($q) =>
                $q->where('code', 'PAWAPAY')->where('is_active', true)
            )->where('is_active', true)->first();

            if (!$webhookSecret) {
                Log::info('[Withdrawal][PawaPay] No webhook secret configured — skipping verification in non-production');
                return true;
            }

            try {
                $secret   = decrypt($webhookSecret->secret_encrypted);
                $expected = hash_hmac('sha256', $body, $secret);
                $valid    = hash_equals($expected, $signature);

                if (!$valid) {
                    Log::warning('[Withdrawal][PawaPay] Signature mismatch in non-production — continuing anyway', [
                        'expected' => substr($expected, 0, 20) . '...',
                        'received' => substr($signature, 0, 20) . '...',
                    ]);
                }

                return true;
            } catch (\Throwable $e) {
                Log::warning('[Withdrawal][PawaPay] Signature check error in non-production', [
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
            Log::error('[Withdrawal][PawaPay] No active webhook signature configured for PAWAPAY');
            return false;
        }

        try {
            $secret   = decrypt($webhookSecret->secret_encrypted);
            $expected = hash_hmac('sha256', $body, $secret);
            return hash_equals($expected, $signature);
        } catch (\Throwable $e) {
            Log::error('[Withdrawal][PawaPay] Signature verification error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
