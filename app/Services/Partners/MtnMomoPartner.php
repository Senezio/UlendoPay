<?php

namespace App\Services\Partners;

use App\Models\Transaction;
use App\Services\PartnerResult;
use App\Services\Contracts\PartnerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MtnMomoPartner implements PartnerInterface
{
    private string $baseUrl;
    private string $environment;

    private string $disbursementSubscriptionKey;
    private string $disbursementUserId;
    private string $disbursementApiKey;

    private int $timeoutSeconds;

    public function __construct()
    {
        $this->baseUrl      = config('services.mtn_momo.base_url', 'https://sandbox.momodeveloper.mtn.com');
        $this->environment  = config('services.mtn_momo.environment', 'sandbox');
        $this->timeoutSeconds = config('services.mtn_momo.timeout', 30);

        $this->disbursementSubscriptionKey = config('services.mtn_momo.disbursement.subscription_key', '');
        $this->disbursementUserId          = config('services.mtn_momo.disbursement.user_id', '');
        $this->disbursementApiKey          = config('services.mtn_momo.disbursement.api_key', '');

        if (empty($this->disbursementSubscriptionKey)) {
            throw new \RuntimeException('MTN MoMo disbursement credentials are not configured.');
        }
    }

    public function disburse(Transaction $transaction): PartnerResult
    {
        $startTime   = microtime(true);
        $reference   = (string) Str::uuid();

        // In sandbox, MTN only accepts EUR regardless of real currency
        $currency = $this->environment === 'sandbox'
            ? 'EUR'
            : $transaction->receive_currency;

        $payload = [
            'amount'      => (string) $transaction->receive_amount,
            'currency'    => $currency,
            'externalId'  => $transaction->reference_number,
            'payee'       => [
                'partyIdType' => 'MSISDN',
                'partyId'     => ltrim($transaction->recipient->mobile_number, '+'),
            ],
            'payerMessage' => 'Ulendo Pay remittance',
            'payeeNote'    => substr('Ref ' . $transaction->reference_number, 0, 160),
        ];

        try {
            $token = $this->getDisbursementToken();

            $response = Http::withToken($token)
                ->withHeaders([
                    'X-Reference-Id'            => $reference,
                    'X-Target-Environment'      => $this->environment,
                    'Ocp-Apim-Subscription-Key' => $this->disbursementSubscriptionKey,
                ])
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/disbursement/v1_0/transfer", $payload);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $body           = $response->json() ?? [];

            Log::info('MTN MoMo disburse response', [
                'reference'      => $transaction->reference_number,
                'momo_reference' => $reference,
                'http_status'    => $response->status(),
                'body'           => $body,
            ]);

            // MTN returns 202 Accepted for successful initiation
            if ($response->status() === 202) {
                return PartnerResult::success(
                    partnerReference: $reference,
                    status:           'ACCEPTED',
                    rawResponse:      $body,
                    responseTimeMs:   $responseTimeMs,
                );
            }

            $failureReason = $body['message'] ?? $body['status'] ?? 'MTN MoMo transfer rejected';

            return PartnerResult::failure(
                failureReason:  $failureReason,
                rawResponse:    $body,
                responseTimeMs: $responseTimeMs,
            );

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::error('MTN MoMo connection timeout', [
                'reference' => $transaction->reference_number,
                'error'     => $e->getMessage(),
            ]);

            return PartnerResult::failure(
                failureReason:  'Connection timeout: ' . $e->getMessage(),
                rawResponse:    [],
                responseTimeMs: $responseTimeMs,
            );
        }
    }

    public function checkStatus(string $partnerReference): PartnerResult
    {
        $startTime = microtime(true);

        try {
            $token = $this->getDisbursementToken();

            $response = Http::withToken($token)
                ->withHeaders([
                    'X-Target-Environment'      => $this->environment,
                    'Ocp-Apim-Subscription-Key' => $this->disbursementSubscriptionKey,
                ])
                ->timeout($this->timeoutSeconds)
                ->get("{$this->baseUrl}/disbursement/v1_0/transfer/{$partnerReference}");

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $body           = $response->json() ?? [];
            $status         = $body['status'] ?? 'UNKNOWN';

            if ($response->successful() && $status === 'SUCCESSFUL') {
                return PartnerResult::success(
                    partnerReference: $partnerReference,
                    status:           $status,
                    rawResponse:      $body,
                    responseTimeMs:   $responseTimeMs,
                );
            }

            if (in_array($status, ['FAILED', 'REJECTED', 'TIMEOUT'])) {
                return PartnerResult::failure(
                    failureReason:    $body['reason'] ?? $status,
                    rawResponse:      $body,
                    responseTimeMs:   $responseTimeMs,
                    partnerReference: $partnerReference,
                );
            }

            // Still pending
            return PartnerResult::failure(
                failureReason:    "Status still pending: {$status}",
                rawResponse:      $body,
                responseTimeMs:   $responseTimeMs,
                partnerReference: $partnerReference,
            );

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            return PartnerResult::failure(
                failureReason:  'Status check timeout: ' . $e->getMessage(),
                rawResponse:    [],
                responseTimeMs: $responseTimeMs,
            );
        }
    }

    /**
     * MTN MoMo handles currencies NOT covered by PawaPay.
     * ZMW and ZAR are excluded here — PawaPay takes priority for those.
     */
    public function supports(string $fromCurrency, string $toCurrency): bool
    {
        return DB::table('partner_corridors')
            ->join('partners', 'partner_corridors.partner_id', '=', 'partners.id')
            ->where('partners.code', 'MTNMOMO')
            ->where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('partner_corridors.is_active', true)
            ->exists();
    }

    private function getDisbursementToken(): string
    {
        $credentials = base64_encode("{$this->disbursementUserId}:{$this->disbursementApiKey}");

        $response = Http::withHeaders([
            'Authorization'             => "Basic {$credentials}",
            'Ocp-Apim-Subscription-Key' => $this->disbursementSubscriptionKey,
        ])->post("{$this->baseUrl}/disbursement/token/");

        if (!$response->successful()) {
            throw new \RuntimeException(
                'Failed to get MTN MoMo disbursement token: ' . $response->body()
            );
        }

        return $response->json('access_token');
    }
}
