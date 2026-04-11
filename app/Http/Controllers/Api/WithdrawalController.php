<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawalService) {}

    /**
     * Get supported operators for the authenticated user's currency.
     */
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

    /**
     * Initiate a withdrawal to mobile money.
     */
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

    /**
     * Get withdrawal status by reference.
     */
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

    /**
     * Get withdrawal history for authenticated user.
     */
    public function history(Request $request): JsonResponse
    {
        $withdrawals = Withdrawal::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json($withdrawals);
    }

    /**
     * Pawapay payout webhook — receives payout status updates.
     * PUBLIC endpoint — secured via signature verification.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload   = $request->all();
        $signature = $request->header('X-Pawapay-Signature') ?? '';

        Log::info('[Withdrawal Webhook] Received', [
            'payload'   => $payload,
            'signature' => substr($signature, 0, 20) . '...',
        ]);

        if (config('app.env') === 'production') {
            if (!$this->verifySignature($request->getContent(), $signature)) {
                Log::warning('[Withdrawal Webhook] Invalid signature');
                return response()->json(['message' => 'Signature verification failed.'], 200);
            }
        }

        try {
            $this->withdrawalService->handleWebhook($payload);
        } catch (\Throwable $e) {
            Log::error('[Withdrawal Webhook] Processing failed', [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

        return response()->json(['message' => 'Webhook received.'], 200);
    }

    private function verifySignature(string $body, string $signature): bool
    {
        $webhookSecret = \App\Models\WebhookSignature::whereHas('partner', fn($q) =>
            $q->where('code', 'PAWAPAY')->where('is_active', true)
        )->where('is_active', true)->first();

        if (!$webhookSecret) {
            Log::error('[Withdrawal Webhook] No active webhook signature configured for PAWAPAY');
            return false;
        }

        try {
            $secret   = decrypt($webhookSecret->secret_encrypted);
            $expected = hash_hmac('sha256', $body, $secret);
            return hash_equals($expected, $signature);
        } catch (\Throwable $e) {
            Log::error('[Withdrawal Webhook] Signature verification error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
