<?php

namespace App\Services;

use App\Models\TopUp;
use App\Models\User;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Services\MtnMomoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TopUpService
{
    private string $baseUrl;
    private string $apiToken;
    private int    $timeoutSeconds;

    private array $correspondentMap = [
        'MWK:AIRTEL'      => 'AIRTEL_MWI',
        'MWK:TNM'         => 'TNM_MWI',
        'MWK:TNM_MPAMBA'  => 'TNM_MPAMBA_MWI',

        'TZS:VODACOM'     => 'VODACOM_TZA',
        'TZS:AIRTEL'      => 'AIRTEL_TZA',
        'TZS:TIGO'        => 'TIGO_TZA',
        'TZS:HALOTEL'     => 'HALOTEL_TZA',

        'KES:MPESA'       => 'MPESA_KEN',
        'KES:MPESA_V2'    => 'MPESA_V2_KEN',
        'KES:AIRTEL'      => 'AIRTEL_KEN',

        'ZMW:AIRTEL'      => 'AIRTEL_ZMB',
        'ZMW:MTN'         => 'MTN_MOMO_ZMB',
        'ZMW:ZAMTEL'      => 'ZAMTEL_ZMB',

        'GHS:MTN'         => 'MTN_MOMO_GHA',
        'GHS:VODAFONE'    => 'VODAFONE_GHA',
        'GHS:AIRTELTIGO'  => 'AIRTELTIGO_GHA',

        'UGX:MTN'         => 'MTN_MOMO_UGA',
        'UGX:AIRTEL'      => 'AIRTEL_UGA',

        'RWF:MTN'         => 'MTN_MOMO_RWA',
        'RWF:AIRTEL'      => 'AIRTEL_RWA',

        'MZN:VODACOM'     => 'VODACOM_MOZ',
        'MZN:MOVITEL'     => 'MOVITEL_MOZ',

        'ETB:TELEBIRR'    => 'TELEBIRR_ETH',
        'ETB:MPESA'       => 'MPESA_ETH',

        'XOF:ORANGE'      => 'ORANGE_SEN',
        'XOF:FREE'        => 'FREE_SEN',
        'XOF:WAVE'        => 'WAVE_SEN',

        'NGN:MTN'         => 'MTN_MOMO_NGA',
        'NGN:AIRTEL'      => 'AIRTEL_NGA',

        'XAF:MTN'         => 'MTN_MOMO_CMR',
        'XAF:ORANGE'      => 'ORANGE_CMR',

        'CDF:VODACOM'     => 'VODACOM_COD',
        'CDF:AIRTEL'      => 'AIRTEL_COD',
        'CDF:ORANGE'      => 'ORANGE_COD',

        'ZAR:MTN'         => 'MTN_MOMO_ZAF',
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

    public function initiate(
        User   $user,
        string $phoneNumber,
        string $mobileOperator,
        float  $amount
    ): TopUp {
        $wallet = $user->wallets()->where('status', 'active')->first();

        if (!$wallet) {
            throw new \RuntimeException("No active wallet found for user {$user->id}.");
        }

        $currency = $wallet->currency_code;

        if ($amount < 1) {
            throw new \RuntimeException("Minimum top-up amount is 1 {$currency}.");
        }

        $countryCode = $user->country_code ?? $this->currencyToCountry($currency);

        // ── Route to MTN MoMo ────────────────────────────────────────────────
        $mtnMomo = new MtnMomoService();
        if ($mtnMomo->supportsCurrency($currency)) {
            $topUp = TopUp::create([
                'reference'       => TopUp::generateReference(),
                'user_id'         => $user->id,
                'wallet_id'       => $wallet->id,
                'amount'          => $amount,
                'currency_code'   => $currency,
                'phone_number'    => $phoneNumber,
                'mobile_operator' => $mobileOperator,
                'country_code'    => $countryCode,
                'provider'        => 'mtnmomo',
                'correspondent'   => 'MTN_MOMO',
                'status'          => 'initiated',
                'initiated_at'    => now(),
            ]);

            // MtnMomoService returns the reference — this service owns the record
            $mtnReference = $mtnMomo->initiateTopUp(
                user:              $user,
                phoneNumber:       $phoneNumber,
                amount:            $amount,
                currency:          $currency,
                externalReference: $topUp->reference
            );

            $topUp->update([
                'provider_reference' => $mtnReference,
                'status'             => 'pending',
            ]);

            return $topUp->fresh();
        }

        // ── Route to PawaPay ─────────────────────────────────────────────────
        $correspondentKey = "{$currency}:{$mobileOperator}";
        $correspondent    = $this->correspondentMap[$correspondentKey] ?? null;

        if (!$correspondent) {
            throw new \RuntimeException(
                "Mobile operator {$mobileOperator} is not supported for {$currency}. " .
                "Available operators: " . $this->getAvailableOperators($currency)
            );
        }

        $providerReference = (string) Str::uuid();

        $topUp = TopUp::create([
            'reference'          => TopUp::generateReference(),
            'user_id'            => $user->id,
            'wallet_id'          => $wallet->id,
            'amount'             => $amount,
            'currency_code'      => $currency,
            'phone_number'       => $phoneNumber,
            'mobile_operator'    => $mobileOperator,
            'country_code'       => $countryCode,
            'provider'           => 'pawapay',
            'provider_reference' => $providerReference,
            'correspondent'      => $correspondent,
            'status'             => 'initiated',
            'initiated_at'       => now(),
        ]);

        $payload = [
            'depositId'            => $providerReference,
            'amount'               => number_format($amount, 2, '.', ''),
            'currency'             => $currency,
            'country'              => $this->currencyToCountry($currency),
            'correspondent'        => $correspondent,
            'payer'                => [
                'type'    => 'MSISDN',
                'address' => ['value' => ltrim($phoneNumber, '+')],
            ],
            'customerTimestamp'    => now()->toIso8601String(),
            'statementDescription' => 'Ulendo Pay topup',
        ];

        try {
            $response = Http::withToken($this->apiToken)
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/deposits", $payload);

            $body = $response->json() ?? [];

            Log::info('[TopUpService] PawaPay deposit initiated', [
                'reference'          => $topUp->reference,
                'provider_reference' => $providerReference,
                'http_status'        => $response->status(),
                'body'               => $body,
            ]);

            $topUp->update([
                'provider_request_payload'  => $payload,
                'provider_response_payload' => $body,
                'status'                    => 'pending',
            ]);

            if (!$response->successful() || ($body['status'] ?? '') === 'REJECTED') {
                $reason = $body['rejectionReason']['rejectionCode'] ?? 'Unknown rejection';

                $topUp->update([
                    'status'         => 'failed',
                    'failure_reason' => $reason,
                    'failed_at'      => now(),
                ]);

                throw new \RuntimeException($this->friendlyRejectionMessage($reason));
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $topUp->update([
                'status'         => 'failed',
                'failure_reason' => 'Connection timeout: ' . $e->getMessage(),
                'failed_at'      => now(),
            ]);

            throw new \RuntimeException('Could not connect to payment provider. Please try again.');
        }

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'topup.initiated',
            'entity_type' => 'TopUp',
            'entity_id'   => $topUp->id,
            'new_values'  => [
                'reference' => $topUp->reference,
                'amount'    => $amount,
                'currency'  => $currency,
                'operator'  => $mobileOperator,
                'provider'  => 'pawapay',
            ],
        ]);

        return $topUp->fresh();
    }

    /**
     * Handle incoming webhook from PawaPay or MTN MoMo.
     * Lookup is by provider_reference — works for both providers.
     * CRITICAL: Must be idempotent.
     */
    public function handleWebhook(array $payload): void
    {
        // PawaPay sends depositId; MTN sends externalId
        $providerReference = $payload['depositId'] ?? $payload['externalId'] ?? null;
        $status            = $payload['status'] ?? null;

        if (!$providerReference || !$status) {
            Log::warning('[TopUpService] Invalid webhook payload — missing reference or status', $payload);
            return;
        }

        $topUp = TopUp::where('provider_reference', $providerReference)
            ->orWhere('reference', $providerReference)
            ->first();

        if (!$topUp) {
            Log::error('[TopUpService] TopUp not found for provider reference', [
                'provider_reference' => $providerReference,
            ]);
            return;
        }

        if ($topUp->status === 'completed') {
            Log::info('[TopUpService] Webhook ignored — already completed', [
                'reference' => $topUp->reference,
            ]);
            return;
        }

        $topUp->update(['provider_webhook_payload' => $payload]);

        $completedStatuses = ['COMPLETED', 'SUCCESSFUL'];
        $failedStatuses    = ['FAILED', 'REJECTED', 'TIMED_OUT', 'CANCELLED'];

        if (in_array($status, $completedStatuses)) {
            $this->creditUserWallet($topUp);

        } elseif (in_array($status, $failedStatuses)) {
            $reason = $payload['rejectionReason']['rejectionCode']
                ?? $payload['failureReason']
                ?? $status;

            $topUp->update([
                'status'         => 'failed',
                'failure_reason' => $reason,
                'failed_at'      => now(),
            ]);

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => null,
                'payload'        => [
                    'type'      => 'topup_failed',
                    'phone'     => $topUp->phone_number,
                    'amount'    => $topUp->amount,
                    'currency'  => $topUp->currency_code,
                    'reference' => $topUp->reference,
                ],
                'status' => 'pending',
            ]);

            Log::info('[TopUpService] TopUp failed via webhook', [
                'reference' => $topUp->reference,
                'reason'    => $reason,
                'provider'  => $topUp->provider,
            ]);

        } else {
            Log::info('[TopUpService] Webhook received intermediate status', [
                'reference' => $topUp->reference,
                'status'    => $status,
                'provider'  => $topUp->provider,
            ]);
        }
    }

    private function creditUserWallet(TopUp $topUp): void
    {
        DB::transaction(function () use ($topUp) {
            $currency = $topUp->currency_code;

            $userAccount = Account::where('owner_id', $topUp->user_id)
                ->where('owner_type', \App\Models\User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $currency)
                ->lockForUpdate()
                ->firstOrFail();

            $systemAccount = Account::where('type', 'system')
                ->where('currency_code', $currency)
                ->lockForUpdate()
                ->firstOrFail();

            app(LedgerService::class)->post(
                reference:   "TOPUP-{$topUp->reference}",
                type:        'adjustment',
                currency:    $currency,
                entries: [
                    [
                        'account_id'  => $systemAccount->id,
                        'type'        => 'debit',
                        'amount'      => $topUp->amount,
                        'description' => "Top-up received: {$topUp->reference}",
                    ],
                    [
                        'account_id'  => $userAccount->id,
                        'type'        => 'credit',
                        'amount'      => $topUp->amount,
                        'description' => "Wallet top-up: {$topUp->reference}",
                    ],
                ],
                description: "Top-up confirmed: {$topUp->reference}"
            );

            $topUp->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            AuditLog::create([
                'user_id'     => $topUp->user_id,
                'action'      => 'topup.completed',
                'entity_type' => 'TopUp',
                'entity_id'   => $topUp->id,
                'new_values'  => [
                    'reference' => $topUp->reference,
                    'amount'    => $topUp->amount,
                    'currency'  => $currency,
                    'provider'  => $topUp->provider,
                ],
            ]);

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => null,
                'payload'        => [
                    'type'      => 'topup_completed',
                    'phone'     => $topUp->phone_number,
                    'amount'    => $topUp->amount,
                    'currency'  => $currency,
                    'reference' => $topUp->reference,
                ],
                'status' => 'pending',
            ]);

            Log::info('[TopUpService] Wallet credited via webhook', [
                'reference' => $topUp->reference,
                'amount'    => $topUp->amount,
                'currency'  => $currency,
                'provider'  => $topUp->provider,
            ]);
        });
    }

    public function getAvailableOperators(string $currency): string
    {
        return collect($this->correspondentMap)
            ->keys()
            ->filter(fn($k) => str_starts_with($k, "{$currency}:"))
            ->map(fn($k) => explode(':', $k)[1])
            ->values()
            ->implode(', ');
    }

    public function getSupportedOperators(string $currency): array
    {
        return collect($this->correspondentMap)
            ->keys()
            ->filter(fn($k) => str_starts_with($k, "{$currency}:"))
            ->map(fn($k) => explode(':', $k)[1])
            ->values()
            ->toArray();
    }

    private function currencyToCountry(string $currency): string
    {
        return match ($currency) {
            'MWK' => 'MWI',
            'TZS' => 'TZA',
            'KES' => 'KEN',
            'ZMW' => 'ZMB',
            'GHS' => 'GHA',
            'UGX' => 'UGA',
            'RWF' => 'RWA',
            'MZN' => 'MOZ',
            'ETB' => 'ETH',
            'XOF' => 'SEN',
            'NGN' => 'NGA',
            'XAF' => 'CMR',
            'ZAR' => 'ZAF',
            'CDF' => 'COD',
            default => throw new \InvalidArgumentException("Unsupported currency: {$currency}"),
        };
    }

    private function friendlyRejectionMessage(string $code): string
    {
        return match($code) {
            'AMOUNT_TOO_LARGE'      => 'The amount exceeds the maximum allowed. Please try a smaller amount.',
            'AMOUNT_TOO_SMALL'      => 'The amount is below the minimum allowed.',
            'INSUFFICIENT_FUNDS'    => 'Your mobile money account has insufficient funds.',
            'INVALID_CORRESPONDENT' => 'This mobile network is not supported for this transaction.',
            'INVALID_CURRENCY'      => 'The currency used is not supported.',
            'INVALID_MSISDN'        => 'The phone number entered is invalid.',
            'LIMIT_REACHED'         => 'You have reached your transaction limit.',
            'PAYEE_REJECTED'        => 'The payment was declined by your mobile network.',
            'SERVICE_UNAVAILABLE'   => 'The mobile payment service is temporarily unavailable.',
            'TIMED_OUT'             => 'The payment request timed out. Please try again.',
            default                 => 'Your payment could not be processed. Please try again or contact support.',
        };
    }
}
