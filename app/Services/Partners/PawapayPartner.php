<?php

namespace App\Services\Partners;

use App\Models\Transaction;
use App\Services\PartnerResult;
use App\Services\Contracts\PartnerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PawapayPartner implements PartnerInterface
{
    private string $baseUrl;
    private string $apiToken;
    private int    $timeoutSeconds;

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

        $payload = [
            'payoutId'             => (string) Str::uuid(),
            'amount'               => number_format((float) $transaction->receive_amount, 2, '.', ''),
            'currency'             => $transaction->receive_currency,
            'country'              => $this->currencyToCountry($transaction->receive_currency),
            'correspondent'        => $this->resolveCorrespondent($transaction),
            'recipient'            => [
                'type'    => 'MSISDN',
                'address' => [
                    'value' => ltrim($transaction->recipient->mobile_number, '+'),
                ],
            ],
            'customerTimestamp'    => now()->toIso8601String(),
            'statementDescription' => substr('Ref ' . preg_replace('/[^a-zA-Z0-9 ]/', '', $transaction->reference_number), 0, 22),
        ];

        try {
            $response = Http::withToken($this->apiToken)
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/payouts", $payload);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $body           = $response->json() ?? [];

            Log::info('Pawapay disburse response', [
                'reference' => $transaction->reference_number,
                'status'    => $response->status(),
                'body'      => $body,
            ]);

            if ($response->successful() && ($body['status'] ?? '') === 'ACCEPTED') {
                return PartnerResult::success(
                    partnerReference: $body['payoutId'] ?? $transaction->reference_number,
                    status:           'ACCEPTED',
                    rawResponse:      $body,
                    responseTimeMs:   $responseTimeMs,
                );
            }

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
                    failureReason:    $body['rejectionReason']['rejectionCode'] ?? $status,
                    rawResponse:      $body,
                    responseTimeMs:   $responseTimeMs,
                    partnerReference: $partnerReference,
                );
            }

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

    public function supports(string $fromCurrency, string $toCurrency): bool
    {
        return DB::table('partner_corridors')
            ->join('partners', 'partner_corridors.partner_id', '=', 'partners.id')
            ->where('partners.code', 'PAWAPAY')
            ->where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('partner_corridors.is_active', true)
            ->exists();
    }

    private function currencyToCountry(string $currency): string
    {
        // NOTE: XOF is shared by BEN, BFA, CIV, SEN and XAF by CMR, COD, COG, GAB.
        // For those currencies the country is derived from the correspondent in the
        // pawaPay payload, so we return a sensible default here; the API will use
        // the correspondent code to route correctly regardless.
        // Covers all 17 active countries from active-conf 2026-04-15.
        return match ($currency) {
            'XOF' => 'BEN',  // BEN / BFA / CIV / SEN — routed via correspondent
            'XAF' => 'CMR',  // CMR / COG / GAB — routed via correspondent
            'CDF' => 'COD',
            'USD' => 'COD',  // USD corridors only active for COD
            'GHS' => 'GHA',
            'KES' => 'KEN',
            'MZN' => 'MOZ',
            'MWK' => 'MWI',
            'RWF' => 'RWA',
            'SLE' => 'SLE',
            'TZS' => 'TZA',
            'UGX' => 'UGA',
            'ZMW' => 'ZMB',
            default => throw new \InvalidArgumentException("Unsupported currency: {$currency}"),
        };
    }

    private function resolveCorrespondent(Transaction $transaction): string
    {
        // Correspondent codes verified against active-conf endpoint 2026-04-15
        // https://docs.pawapay.io/correspondents
        $operator = strtoupper($transaction->recipient->mobile_network ?? '');
        $currency = $transaction->receive_currency;

        return match ("{$currency}:{$operator}") {
            // Benin (XOF)
            'XOF:MOOV'          => 'MOOV_BEN',
            'XOF:MTN'           => 'MTN_MOMO_BEN',

            // Burkina Faso (XOF)
            'XOF:MOOV_BFA'      => 'MOOV_BFA',

            // Côte d'Ivoire (XOF)
            'XOF:MTN_CIV'       => 'MTN_MOMO_CIV',
            'XOF:ORANGE_CIV'    => 'ORANGE_CIV',

            // Senegal (XOF)
            'XOF:FREE'          => 'FREE_SEN',
            'XOF:ORANGE_SEN'    => 'ORANGE_SEN',

            // Cameroon (XAF)
            'XAF:MTN'           => 'MTN_MOMO_CMR',
            'XAF:ORANGE_CMR'    => 'ORANGE_CMR',

            // Congo-Brazzaville (XAF)
            'XAF:AIRTEL_COG'    => 'AIRTEL_COG',
            'XAF:MTN_COG'       => 'MTN_MOMO_COG',

            // Gabon (XAF)
            'XAF:AIRTEL_GAB'    => 'AIRTEL_GAB',

            // DR Congo (CDF)
            'CDF:AIRTEL'        => 'AIRTEL_COD',
            'CDF:ORANGE'        => 'ORANGE_COD',
            'CDF:VODACOM'       => 'VODACOM_MPESA_COD',

            // DR Congo (USD)
            'USD:AIRTEL'        => 'AIRTEL_COD',
            'USD:ORANGE'        => 'ORANGE_COD',
            'USD:VODACOM'       => 'VODACOM_MPESA_COD',

            // Ghana (GHS)
            'GHS:AIRTELTIGO'    => 'AIRTELTIGO_GHA',
            'GHS:MTN'           => 'MTN_MOMO_GHA',
            'GHS:VODAFONE'      => 'VODAFONE_GHA',

            // Kenya (KES)
            'KES:SAFARICOM'     => 'MPESA_KEN',

            // Mozambique (MZN)
            'MZN:VODACOM'       => 'VODACOM_MOZ',

            // Malawi (MWK)
            'MWK:AIRTEL'        => 'AIRTEL_MWI',
            'MWK:TNM'           => 'TNM_MWI',

            // Rwanda (RWF)
            'RWF:AIRTEL'        => 'AIRTEL_RWA',
            'RWF:MTN'           => 'MTN_MOMO_RWA',

            // Sierra Leone (SLE)
            'SLE:ORANGE'        => 'ORANGE_SLE',

            // Tanzania (TZS)
            'TZS:AIRTEL'        => 'AIRTEL_TZA',
            'TZS:HALOTEL'       => 'HALOTEL_TZA',
            'TZS:TIGO'          => 'TIGO_TZA',

            // Uganda (UGX)
            'UGX:AIRTEL'        => 'AIRTEL_OAPI_UGA',
            'UGX:MTN'           => 'MTN_MOMO_UGA',

            // Zambia (ZMW)
            'ZMW:AIRTEL'        => 'AIRTEL_OAPI_ZMB',
            'ZMW:MTN'           => 'MTN_MOMO_ZMB',
            'ZMW:ZAMTEL'        => 'ZAMTEL_ZMB',

            default => throw new \InvalidArgumentException(
                "No correspondent mapping for {$currency}:{$operator}"
            ),
        };
    }
}
