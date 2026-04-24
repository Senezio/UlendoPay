<?php

namespace App\Http\Controllers\Concerns;

use App\Models\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

trait VerifiesWebhookSignature
{
    /**
     * Orchestrator — loads the WebhookSignature record for the given partner
     * code, checks the environment bypass, then delegates to the correct
     * verifier based on the stored algorithm.
     */
    private function verifyPawapaySignature(Request $request): bool
    {
        $record = WebhookSignature::whereHas('partner', fn($q) =>
            $q->where('code', 'PAWAPAY')->where('is_active', true)
        )->where('is_active', true)->first();

        // Non-production: attempt verification for early bug detection,
        // but always pass so sandbox testing is never blocked.
        if (config('app.env') !== 'production') {
            if (!$record) {
                Log::info('[PawaPay] No webhook signature record — skipping verification in non-production');
                return true;
            }

            try {
                $this->dispatchSignatureVerification($request, $record);
            } catch (\Throwable $e) {
                Log::warning('[PawaPay] Signature check error in non-production', [
                    'error' => $e->getMessage(),
                ]);
            }

            return true;
        }

        // Production — strict.
        if (!$record) {
            Log::error('[PawaPay] No active webhook signature record found');
            return false;
        }

        try {
            return $this->dispatchSignatureVerification($request, $record);
        } catch (\Throwable $e) {
            Log::error('[PawaPay] Signature verification error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Reads the algorithm from the record and delegates to the
     * correct verifier. Throws if the algorithm is unsupported.
     */
    private function dispatchSignatureVerification(Request $request, WebhookSignature $record): bool
    {
        $secret = decrypt($record->getRawOriginal('secret_encrypted'));

        return match ($record->algorithm) {
            'ecdsa-p256-sha256' => $this->verifyEcdsaSignature($request, $secret),
            'hmac-sha256'       => $this->verifyHmacSignature(
                                       $request->getContent(),
                                       $request->header('X-Pawapay-Signature') ?? '',
                                       $secret
                                   ),
            default => throw new \RuntimeException(
                "Unsupported webhook signature algorithm: {$record->algorithm}"
            ),
        };
    }

    /**
     * RFC-9421 ECDSA P-256 SHA-256 verification.
     * Covers: Content-Digest, signature base construction, and openssl_verify.
     */
    private function verifyEcdsaSignature(Request $request, string $publicKey): bool
    {
        $body          = $request->getContent();
        $contentDigest = $request->header('Content-Digest') ?? '';
        $sigInput      = $request->header('Signature-Input') ?? '';
        $sigHeader     = $request->header('Signature') ?? '';
        $sigDate       = $request->header('Signature-Date') ?? '';

        // ── 1. Verify Content-Digest ──────────────────────────────────────
        if (preg_match('/sha-512=:([^:]+):/', $contentDigest, $m)) {
            $expected = base64_encode(hash('sha512', $body, true));
            if (!hash_equals($expected, $m[1])) {
                Log::warning('[PawaPay] Content-Digest sha-512 mismatch');
                return false;
            }
        } elseif (preg_match('/sha-256=:([^:]+):/', $contentDigest, $m)) {
            $expected = base64_encode(hash('sha256', $body, true));
            if (!hash_equals($expected, $m[1])) {
                Log::warning('[PawaPay] Content-Digest sha-256 mismatch');
                return false;
            }
        } else {
            Log::warning('[PawaPay] Missing or unsupported Content-Digest', [
                'content-digest' => $contentDigest,
            ]);
            return false;
        }

        // ── 2. Extract base64 signature from sig-pp=:BASE64: ─────────────
        if (!preg_match('/sig-pp=:([^:]+):/', $sigHeader, $sm)) {
            Log::warning('[PawaPay] Missing sig-pp in Signature header', [
                'signature' => $sigHeader,
            ]);
            return false;
        }
        $rawSig = base64_decode($sm[1]);

        // ── 3. Build RFC-9421 signature base ──────────────────────────────
        // Covered fields from Signature-Input:
        // ("@method" "@authority" "@path" "signature-date" "content-digest" "content-type")
        $sigParamsValue = preg_replace('/^sig-pp=/', '', $sigInput);

        $sigBase  = '"@method": '        . strtoupper($request->method())             . "\n";
        $sigBase .= '"@authority": '     . strtolower($request->getHost())             . "\n";
        $sigBase .= '"@path": '          . $request->getRequestUri()                   . "\n";
        $sigBase .= '"signature-date": ' . $sigDate                                    . "\n";
        $sigBase .= '"content-digest": ' . $contentDigest                              . "\n";
        $sigBase .= '"content-type": '   . ($request->header('Content-Type') ?? '')   . "\n";
        $sigBase .= '"@signature-params": ' . $sigParamsValue;

        // ── 4. Verify ECDSA P-256 ─────────────────────────────────────────
        $pubKey = openssl_pkey_get_public($publicKey);

        if (!$pubKey) {
            Log::error('[PawaPay] Failed to load public key', [
                'openssl_error' => openssl_error_string(),
            ]);
            return false;
        }

        $result = openssl_verify($sigBase, $rawSig, $pubKey, OPENSSL_ALGO_SHA256);

        if ($result !== 1) {
            Log::warning('[PawaPay] ECDSA signature verification failed', [
                'openssl_error' => openssl_error_string(),
                'result'        => $result,
            ]);
            return false;
        }

        return true;
    }

    /**
     * HMAC-SHA256 verification — for partners that use shared secret signing.
     * Kept for future partners; not currently used by PawaPay.
     */
    private function verifyHmacSignature(string $body, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $signature);
    }
}
