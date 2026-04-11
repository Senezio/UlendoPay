<?php

namespace App\Services\Partners;

use App\Models\Transaction;
use App\Services\PartnerResult;
use App\Services\Contracts\PartnerInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PawapayPartner implements PartnerInterface
{
    private string $baseUrl;
    private string $apiToken;
    private int    $timeoutSeconds;

    // Pawapay supported corridors — expand as you onboard more
    private array $supportedCorridors = [
        'MWK-TZS', 'MWK-KES', 'MWK-ZMW',
        'MWK-ZAR', 'MWK-MZN', 'MWK-BWP',
        'MWK-ETB', 'MWK-MGA',
    ];

    public function __construct()
    {
        $this->baseUrl        = config('services.pawapay.base_url', 'https://api.pawapay.io');
        $this->apiToken       = config('services.pawapay.api_token', '');
        $this->timeoutSeconds = config('services.pawapay.timeout', 30);

        if (empty($this->apiToken)) {
            throw new \RuntimeException('Pawapay API token is not configured.');
        }
    }

    public function disburse(Transaction $transaction): PartnerResult
    {
        $startTime = microtime(true);

        // Build Pawapay-specific payload
        // Docs: https://docs.pawapay.io/payouts
        $payload = [
            'payoutId'          => $transaction->reference_number,
            'amount'            => (string) $transaction->receive_amount,
            'currency'          => $transaction->receive_currency,
            'country'           => $this->currencyToCountry($transaction->receive_currency),
            'correspondent'     => $this->resolveCorrespondent($transaction),
            'recipient'         => [
                'type'    => 'MSISDN',
                'address' => [
                    'value' => $transaction->recipient->mobile_number,
                ],
            ],
            'customerTimestamp' => now()->toIso8601String(),
            'statementDescription' => 'UlendoPay Transfer ' . $transaction->reference_number,
        ];

        try {
            $response = Http::withToken($this->apiToken)
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/payouts", $payload);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $body           = $response->json() ?? [];

            Log::info('Pawapay disburse response', [
                'reference'      => $transaction->reference_number,
                'status'         => $response->status(),
                'body'           => $body,
            ]);

            // Pawapay returns 200 with status ACCEPTED for successful initiation
            if ($response->successful() && ($body['status'] ?? '') === 'ACCEPTED') {
                return PartnerResult::success(
                    partnerReference: $body['payoutId'] ?? $transaction->reference_number,
                    status:           'ACCEPTED',
                    rawResponse:      $body,
                    responseTimeMs:   $responseTimeMs,
                );
            }

            // Pawapay-specific failure codes
            $failureReason = $body['rejectionReason']['rejectionCode']
                ?? $body['message']
                ?? 'Unknown Pawapay rejection';

            return PartnerResult::failure(
                failureReason:  $failureReason,
                rawResponse:    $body,
                responseTimeMs: $responseTimeMs,
            );

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::error('Pawapay connection timeout', [
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
            $response = Http::withToken($this->apiToken)
                ->timeout($this->timeoutSeconds)
                ->get("{$this->baseUrl}/payouts/{$partnerReference}");

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $body           = $response->json() ?? [];
            $status         = $body['status'] ?? 'UNKNOWN';

            if ($response->successful() && $status === 'COMPLETED') {
                return PartnerResult::success(
                    partnerReference: $partnerReference,
                    status:           $status,
                    rawResponse:      $body,
                    responseTimeMs:   $responseTimeMs,
                );
            }

            if (in_array($status, ['FAILED', 'REJECTED', 'TIMED_OUT'])) {
                return PartnerResult::failure(
                    failureReason:  $body['rejectionReason']['rejectionCode'] ?? $status,
                    rawResponse:    $body,
                    responseTimeMs: $responseTimeMs,
                    partnerReference: $partnerReference,
                );
            }

            // Still pending — not a failure, not a success
            return PartnerResult::failure(
                failureReason:  "Status still pending: {$status}",
                rawResponse:    $body,
                responseTimeMs: $responseTimeMs,
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

    public function supports(string $fromCurrency, string $toCurrency): bool
    {
        return in_array("{$fromCurrency}-{$toCurrency}", $this->supportedCorridors);
    }

    private function currencyToCountry(string $currency): string
    {
        return match($currency) {
            'TZS' => 'TZA',
            'KES' => 'KEN',
            'ZMW' => 'ZMB',
            'ZAR' => 'ZAF',
            'MZN' => 'MOZ',
            'BWP' => 'BWA',
            'ETB' => 'ETH',
            'MGA' => 'MDG',
            'MWK' => 'MWI',
            default => throw new \InvalidArgumentException("Unsupported currency: {$currency}"),
        };
    }

    private function resolveCorrespondent(Transaction $transaction): string
    {
        // Pawapay requires the specific mobile operator code
        // These codes come from Pawapay's correspondent list
        // https://docs.pawapay.io/correspondents
        $operator = strtoupper($transaction->recipient->mobile_network ?? '');
        $currency = $transaction->receive_currency;

        return match("{$currency}:{$operator}") {
            'TZS:VODACOM'  => 'VODACOM_TZA_MOBILE',
            'TZS:AIRTEL'   => 'AIRTEL_TZA_MOBILE',
            'TZS:TIGO'     => 'TIGO_TZA_MOBILE',
            'KES:SAFARICOM'=> 'MPESA_KEN_MOBILE',
            'KES:AIRTEL'   => 'AIRTEL_KEN_MOBILE',
            'ZMW:AIRTEL'   => 'AIRTEL_ZMB_MOBILE',
            'ZMW:MTN'      => 'MTN_ZMB_MOBILE',
            'ZAR:MTN'      => 'MTN_ZAF_MOBILE',
            'MWK:AIRTEL'   => 'AIRTEL_MWI_MOBILE',
            'MWK:TNM'      => 'TNM_MWI_MOBILE',
            default => throw new \InvalidArgumentException(
                "No correspondent mapping for {$currency}:{$operator}"
            ),
        };
    }
}
