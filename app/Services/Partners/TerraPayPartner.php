<?php

namespace App\Services\Partners;

use App\Models\Transaction;
use App\Services\PartnerResult;
use App\Services\Contracts\PartnerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TerraPayPartner implements PartnerInterface
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private int    $timeoutSeconds;

    public function __construct()
    {
        $this->baseUrl        = config('services.terrapay.base_url', 'https://uat-connect.terrapay.com:21211');
        $this->username       = config('services.terrapay.username', '');
        $this->password       = config('services.terrapay.password', '');
        $this->timeoutSeconds = config('services.terrapay.timeout', 30);

        if (empty($this->username) || empty($this->password)) {
            throw new \RuntimeException('TerraPay credentials are not configured.');
        }
    }

    public function disburse(Transaction $transaction): PartnerResult
    {
        $startTime = microtime(true);
        $recipient = $transaction->recipient;
        $sender    = $transaction->sender;

        $this->validateBankDetails($recipient);

        $quoteResult = $this->requestQuote($transaction);

        if (!$quoteResult['success']) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            return PartnerResult::failure(
                failureReason:  'Quote failed: ' . $quoteResult['reason'],
                rawResponse:    $quoteResult['body'],
                responseTimeMs: $responseTimeMs,
            );
        }

        $transactionReference = (string) Str::uuid();
        $senderName           = $this->splitName($sender->name ?? '');
        $beneficiaryName      = $this->splitName($recipient->full_name ?? '');

        $payload = [
            'transactionReference' => $transactionReference,
            'quoteId'              => $quoteResult['quoteId'],
            'sender'               => [
                'accountNumber' => (string) $transaction->sender_id,
                'firstName'     => $senderName['first'],
                'lastName'      => $senderName['last'],
                'countryCode'   => strtoupper($sender->country_code ?? ''),
                'currency'      => $transaction->send_currency,
                'amount'        => (string) number_format((float) $transaction->send_amount, 2, '.', ''),
            ],
            'beneficiary'          => [
                'accountNumber' => $recipient->bank_account_number,
                'firstName'     => $beneficiaryName['first'],
                'lastName'      => $beneficiaryName['last'],
                'bankCode'      => $recipient->bank_branch_code,
                'bankName'      => $recipient->bank_name ?? '',
                'countryCode'   => strtoupper($recipient->country_code ?? ''),
                'currency'      => $transaction->receive_currency,
                'amount'        => (string) number_format((float) $transaction->receive_amount, 2, '.', ''),
            ],
            'transferType'        => $this->resolveTransferType($recipient->payment_method ?? ''),
            'purposeOfRemittance' => $transaction->transfer_purpose ?? '',
        ];

        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/eig/gsma/transactions", $payload);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $body           = $response->json() ?? [];

            Log::info('TerraPay disburse response', [
                'reference' => $transaction->reference_number,
                'status'    => $response->status(),
                'body'      => $body,
            ]);

            $tpStatus = $body['status'] ?? $body['transactionStatus'] ?? '';

            if ($response->successful() && in_array($tpStatus, ['RECEIVED', 'PENDING', 'COMPLETED'])) {
                return PartnerResult::success(
                    partnerReference: $body['transactionReference'] ?? $transactionReference,
                    status:           $tpStatus,
                    rawResponse:      $body,
                    responseTimeMs:   $responseTimeMs,
                );
            }

            $reason = $body['errorMessage']
                ?? $body['message']
                ?? $body['description']
                ?? 'Unknown TerraPay rejection';

            return PartnerResult::failure(
                failureReason:  $reason,
                rawResponse:    $body,
                responseTimeMs: $responseTimeMs,
            );

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::error('TerraPay connection timeout', [
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
            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout($this->timeoutSeconds)
                ->get("{$this->baseUrl}/eig/gsma/transactions/{$partnerReference}");

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $body           = $response->json() ?? [];
            $status         = $body['status'] ?? $body['transactionStatus'] ?? 'UNKNOWN';

            if ($response->successful() && $status === 'COMPLETED') {
                return PartnerResult::success(
                    partnerReference: $partnerReference,
                    status:           $status,
                    rawResponse:      $body,
                    responseTimeMs:   $responseTimeMs,
                );
            }

            if (in_array($status, ['FAILED', 'REJECTED', 'CANCELLED', 'EXPIRED'])) {
                return PartnerResult::failure(
                    failureReason:    $body['errorMessage'] ?? $body['description'] ?? $status,
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
            ->where('partners.code', 'TERRAPAY')
            ->where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('partner_corridors.is_active', true)
            ->exists();
    }

    /**
     * @param  Transaction  $transaction
     * @return array
     */
    private function requestQuote(Transaction $transaction): array
    {
        $payload = [
            'requestDate'          => now()->toIso8601String(),
            'sendingAccountNumber' => (string) $transaction->sender_id,
            'sendingCurrency'      => $transaction->send_currency,
            'sendingAmount'        => (string) number_format((float) $transaction->send_amount, 2, '.', ''),
            'receivingCurrency'    => $transaction->receive_currency,
            'receivingCountry'     => strtoupper($transaction->recipient->country_code ?? ''),
            'transferType'         => $this->resolveTransferType($transaction->recipient->payment_method ?? ''),
        ];

        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/eig/gsma/quotations", $payload);

            $body = $response->json() ?? [];

            Log::info('TerraPay quote response', [
                'corridor' => "{$transaction->send_currency}-{$transaction->receive_currency}",
                'status'   => $response->status(),
                'body'     => $body,
            ]);

            if ($response->successful() && !empty($body['quoteId'])) {
                return [
                    'success' => true,
                    'quoteId' => $body['quoteId'],
                    'body'    => $body,
                ];
            }

            return [
                'success' => false,
                'reason'  => $body['errorMessage'] ?? $body['message'] ?? 'No quoteId returned',
                'body'    => $body,
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'success' => false,
                'reason'  => 'Quote connection timeout: ' . $e->getMessage(),
                'body'    => [],
            ];
        }
    }

    /**
     * @param  string  $paymentMethod
     * @return string
     */
    private function resolveTransferType(string $paymentMethod): string
    {
        return match($paymentMethod) {
            'bank_transfer' => 'BANK',
            'mobile_money'  => 'WALLET',
            'cash_pickup'   => 'CASH',
            default         => throw new \InvalidArgumentException(
                "Unsupported payment method for TerraPay: {$paymentMethod}"
            ),
        };
    }

    /**
     * @param  mixed  $recipient
     * @return void
     */
    private function validateBankDetails(mixed $recipient): void
    {
        if (empty($recipient->bank_account_number)) {
            throw new \RuntimeException('Recipient bank account number is missing.');
        }

        if (empty($recipient->bank_branch_code)) {
            throw new \RuntimeException('Recipient bank branch code is missing.');
        }

        if (empty($recipient->country_code)) {
            throw new \RuntimeException('Recipient country code is missing.');
        }
    }

    /**
     * @param  string  $fullName
     * @return array
     */
    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);
        return [
            'first' => $parts[0] ?? $fullName,
            'last'  => $parts[1] ?? $parts[0] ?? $fullName,
        ];
    }
}
