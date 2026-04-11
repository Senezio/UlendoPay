<?php

namespace App\Services;

use App\Models\TopUp;
use App\Models\User;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TopUpService
{
    private string $baseUrl;
    private string $apiToken;
    private int    $timeoutSeconds;

    // Pawapay correspondent codes — verified against /v1/availability sandbox endpoint
    private array $correspondentMap = [
        // Malawi
        'MWK:AIRTEL'      => 'AIRTEL_MWI',
        'MWK:TNM'         => 'TNM_MWI',
        'MWK:TNM_MPAMBA'  => 'TNM_MPAMBA_MWI',
        // Tanzania
        'TZS:VODACOM'     => 'VODACOM_TZA',
        'TZS:AIRTEL'      => 'AIRTEL_TZA',
        'TZS:TIGO'        => 'TIGO_TZA',
        'TZS:HALOTEL'     => 'HALOTEL_TZA',
        // Kenya
        'KES:MPESA'       => 'MPESA_KEN',
        'KES:MPESA_V2'    => 'MPESA_V2_KEN',
        'KES:AIRTEL'      => 'AIRTEL_KEN',
        // Zambia
        'ZMW:AIRTEL'      => 'AIRTEL_ZMB',
        'ZMW:MTN'         => 'MTN_MOMO_ZMB',
        'ZMW:ZAMTEL'      => 'ZAMTEL_ZMB',
        // Ghana
        'GHS:MTN'         => 'MTN_MOMO_GHA',
        'GHS:VODAFONE'    => 'VODAFONE_GHA',
        'GHS:AIRTELTIGO'  => 'AIRTELTIGO_GHA',
        // Uganda
        'UGX:MTN'         => 'MTN_MOMO_UGA',
        'UGX:AIRTEL'      => 'AIRTEL_UGA',
        // Rwanda
        'RWF:MTN'         => 'MTN_MOMO_RWA',
        'RWF:AIRTEL'      => 'AIRTEL_RWA',
        // Mozambique
        'MZN:VODACOM'     => 'VODACOM_MOZ',
        'MZN:MOVITEL'     => 'MOVITEL_MOZ',
        // Ethiopia
        'ETB:TELEBIRR'    => 'TELEBIRR_ETH',
        'ETB:MPESA'       => 'MPESA_ETH',
        // Senegal
        'XOF:ORANGE'      => 'ORANGE_SEN',
        'XOF:FREE'        => 'FREE_SEN',
        'XOF:WAVE'        => 'WAVE_SEN',
        // Nigeria
        'NGN:MTN'         => 'MTN_MOMO_NGA',
        'NGN:AIRTEL'      => 'AIRTEL_NGA',
        // Cameroon
        'XAF:MTN'         => 'MTN_MOMO_CMR',
        'XAF:ORANGE'      => 'ORANGE_CMR',
        // DRC
        'CDF:VODACOM'     => 'VODACOM_COD',
        'CDF:AIRTEL'      => 'AIRTEL_COD',
        'CDF:ORANGE'      => 'ORANGE_COD',
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

        $correspondentKey = "{$currency}:{$mobileOperator}";
        $correspondent    = $this->correspondentMap[$correspondentKey] ?? null;

        if (!$correspondent) {
            throw new \RuntimeException(
                "Mobile operator {$mobileOperator} is not supported for {$currency}. " .
                "Available operators: " . $this->getAvailableOperators($currency)
            );
        }

        $countryCode      = $user->country_code ?? $this->currencyToCountry($currency);
        $pawapayDepositId = (string) Str::uuid();

        $topUp = TopUp::create([
            'reference'          => TopUp::generateReference(),
            'user_id'            => $user->id,
            'wallet_id'          => $wallet->id,
            'amount'             => $amount,
            'currency_code'      => $currency,
            'phone_number'       => $phoneNumber,
            'mobile_operator'    => $mobileOperator,
            'country_code'       => $countryCode,
            'correspondent'      => $correspondent,
            'pawapay_deposit_id' => $pawapayDepositId,
            'status'             => 'initiated',
            'initiated_at'       => now(),
        ]);

        $payload = [
            'depositId'            => $pawapayDepositId,
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

            Log::info('[TopUpService] Pawapay deposit initiated', [
                'reference'          => $topUp->reference,
                'pawapay_deposit_id' => $pawapayDepositId,
                'http_status'        => $response->status(),
                'body'               => $body,
            ]);

            $topUp->update([
                'pawapay_request_payload'  => $payload,
                'pawapay_response_payload' => $body,
                'status'                   => 'pending',
            ]);

            if (!$response->successful() || ($body['status'] ?? '') === 'REJECTED') {
                $reason = $body['rejectionReason']['rejectionCode'] ?? 'Unknown rejection';

                $topUp->update([
                    'status'         => 'failed',
                    'failure_reason' => $reason,
                    'failed_at'      => now(),
                ]);

                throw new \RuntimeException("Payment initiation rejected: {$reason}");
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
            ],
        ]);

        return $topUp->fresh();
    }

    public function handleWebhook(array $payload): void
    {
        $depositId = $payload['depositId'] ?? null;
        $status    = $payload['status'] ?? null;

        if (!$depositId || !$status) {
            Log::warning('[TopUpService] Invalid webhook payload', $payload);
            return;
        }

        $topUp = TopUp::where('pawapay_deposit_id', $depositId)
            ->orWhere('reference', $depositId)
            ->first();

        if (!$topUp) {
            Log::error('[TopUpService] TopUp not found for deposit', ['depositId' => $depositId]);
            return;
        }

        if ($topUp->isCompleted()) {
            Log::info('[TopUpService] Webhook ignored — already completed', ['depositId' => $depositId]);
            return;
        }

        $topUp->update(['pawapay_webhook_payload' => $payload]);

        if ($status === 'COMPLETED') {
            $this->creditWallet($topUp);
        } elseif (in_array($status, ['FAILED', 'REJECTED', 'TIMED_OUT', 'EXPIRED'])) {
            $topUp->update([
                'status'         => 'failed',
                'failure_reason' => $payload['rejectionReason']['rejectionCode'] ?? $status,
                'failed_at'      => now(),
            ]);

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => null,
                'payload'        => [
                    'type'      => 'topup_failed',
                    'user_id'   => $topUp->user_id,
                    'phone'     => $topUp->phone_number,
                    'amount'    => $topUp->amount,
                    'currency'  => $topUp->currency_code,
                    'reference' => $topUp->reference,
                ],
                'status' => 'pending',
            ]);

            Log::warning('[TopUpService] Top-up failed via webhook', [
                'reference' => $topUp->reference,
                'reason'    => $topUp->failure_reason,
            ]);
        }
    }

    private function creditWallet(TopUp $topUp): void
    {
        DB::transaction(function () use ($topUp) {
            $userAccount = Account::where('code', "USR-{$topUp->user_id}-{$topUp->currency_code}")
                ->lockForUpdate()
                ->firstOrFail();

            $systemAccount = Account::where('code', "{$topUp->currency_code}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

            app(LedgerService::class)->post(
                reference:   "TOPUP-{$topUp->reference}",
                type:        'adjustment',
                currency:    $topUp->currency_code,
                entries:     [
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
                description: "Mobile money top-up: {$topUp->reference}"
            );

            $topUp->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => null,
                'payload'        => [
                    'type'      => 'topup_completed',
                    'user_id'   => $topUp->user_id,
                    'phone'     => $topUp->phone_number,
                    'amount'    => $topUp->amount,
                    'currency'  => $topUp->currency_code,
                    'reference' => $topUp->reference,
                ],
                'status' => 'pending',
            ]);

            AuditLog::create([
                'user_id'     => $topUp->user_id,
                'action'      => 'topup.completed',
                'entity_type' => 'TopUp',
                'entity_id'   => $topUp->id,
                'new_values'  => [
                    'reference' => $topUp->reference,
                    'amount'    => $topUp->amount,
                    'currency'  => $topUp->currency_code,
                ],
            ]);

            Log::info('[TopUpService] Wallet credited', [
                'reference' => $topUp->reference,
                'amount'    => $topUp->amount,
                'currency'  => $topUp->currency_code,
                'user_id'   => $topUp->user_id,
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
        return match($currency) {
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
            'CDF' => 'COD',
            default => throw new \InvalidArgumentException("Unsupported currency: {$currency}"),
        };
    }
}
