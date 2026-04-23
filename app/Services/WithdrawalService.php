<?php

namespace App\Services;

use App\Models\Withdrawal;
use App\Models\User;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WithdrawalService
{
    private string $baseUrl;
    private string $apiToken;
    private int    $timeoutSeconds;

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
        // Cameroon / DRC
        'XAF:MTN'         => 'MTN_MOMO_CMR',
        'XAF:ORANGE'      => 'ORANGE_CMR',
        'CDF:VODACOM'     => 'VODACOM_COD',
        'CDF:AIRTEL'      => 'AIRTEL_COD',
        'CDF:ORANGE'      => 'ORANGE_COD',
        // South Africa
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
    ): Withdrawal {
        $wallet = $user->wallets()->where('status', 'active')->first();

        if (!$wallet) {
            throw new \RuntimeException("No active wallet found for user {$user->id}.");
        }

        $currency = $wallet->currency_code;

        if ($amount < 1) {
            throw new \RuntimeException("Minimum withdrawal amount is 1 {$currency}.");
        }

        $userAccount = Account::where('owner_id', $user->id)
            ->where('owner_type', User::class)
            ->where('type', 'user_wallet')
            ->where('currency_code', $currency)
            ->first();

        if (!$userAccount) {
            throw new \RuntimeException("Wallet account not found.");
        }

        $balance = (float) ($userAccount->balance?->balance ?? 0);

        if ($amount > $balance) {
            throw new \RuntimeException(
                "Insufficient balance. Available: {$currency} " . number_format($balance, 2)
            );
        }

        $countryCode = $user->country_code ?? $this->currencyToCountry($currency);

        // ── Route to MTN MoMo ────────────────────────────────────────────────
        $mtnMomo = new MtnMomoService();
        if ($mtnMomo->supportsCurrency($currency)) {
            $withdrawal = DB::transaction(function () use (
                $user, $wallet, $amount, $currency,
                $phoneNumber, $mobileOperator, $countryCode
            ) {
                $userAccount = Account::where('owner_id', $user->id)
                    ->where('owner_type', User::class)
                    ->where('type', 'user_wallet')
                    ->where('currency_code', $currency)
                    ->lockForUpdate()
                    ->firstOrFail();

                $systemAccount = Account::where('code', "{$currency}-POOL")
                    ->lockForUpdate()
                    ->firstOrFail();

                $withdrawal = Withdrawal::create([
                    'reference'       => Withdrawal::generateReference(),
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

                app(LedgerService::class)->post(
                    reference:   "WDR-{$withdrawal->reference}",
                    type:        'adjustment',
                    currency:    $currency,
                    entries: [
                        [
                            'account_id'  => $userAccount->id,
                            'type'        => 'debit',
                            'amount'      => $amount,
                            'description' => "Withdrawal: {$withdrawal->reference}",
                        ],
                        [
                            'account_id'  => $systemAccount->id,
                            'type'        => 'credit',
                            'amount'      => $amount,
                            'description' => "Withdrawal held: {$withdrawal->reference}",
                        ],
                    ],
                    description: "MTN MoMo withdrawal: {$withdrawal->reference}"
                );

                return $withdrawal;
            });

            // MtnMomoService returns the reference — this service owns the record
            $mtnReference = $mtnMomo->initiateWithdrawal(
                user:              $user,
                phoneNumber:       $phoneNumber,
                amount:            $amount,
                currency:          $currency,
                externalReference: $withdrawal->reference
            );

            $withdrawal->update([
                'provider_reference' => $mtnReference,
                'status'             => 'pending',
            ]);

            return $withdrawal->fresh();
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

        $withdrawal = DB::transaction(function () use (
            $user, $wallet, $amount, $currency, $phoneNumber,
            $mobileOperator, $countryCode, $correspondent, $providerReference
        ) {
            $userAccount = Account::where('owner_id', $user->id)
                ->where('owner_type', User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $currency)
                ->lockForUpdate()
                ->firstOrFail();

            $systemAccount = Account::where('code', "{$currency}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

            $withdrawal = Withdrawal::create([
                'reference'          => Withdrawal::generateReference(),
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

            app(LedgerService::class)->post(
                reference:   "WDR-{$withdrawal->reference}",
                type:        'adjustment',
                currency:    $currency,
                entries: [
                    [
                        'account_id'  => $userAccount->id,
                        'type'        => 'debit',
                        'amount'      => $amount,
                        'description' => "Withdrawal: {$withdrawal->reference}",
                    ],
                    [
                        'account_id'  => $systemAccount->id,
                        'type'        => 'credit',
                        'amount'      => $amount,
                        'description' => "Withdrawal held: {$withdrawal->reference}",
                    ],
                ],
                description: "Mobile money withdrawal: {$withdrawal->reference}"
            );

            return $withdrawal;
        });

        $payload = [
            'payoutId'             => $providerReference,
            'amount'               => number_format($amount, 2, '.', ''),
            'currency'             => $currency,
            'country'              => $this->currencyToCountry($currency),
            'correspondent'        => $correspondent,
            'recipient'            => [
                'type'    => 'MSISDN',
                'address' => ['value' => ltrim($phoneNumber, '+')],
            ],
            'customerTimestamp'    => now()->toIso8601String(),
            'statementDescription' => 'Ulendo Pay withdrawal',
        ];

        try {
            $response = Http::withToken($this->apiToken)
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/payouts", $payload);

            $body = $response->json() ?? [];

            Log::info('[WithdrawalService] PawaPay payout initiated', [
                'reference'          => $withdrawal->reference,
                'provider_reference' => $providerReference,
                'http_status'        => $response->status(),
                'body'               => $body,
            ]);

            $withdrawal->update([
                'provider_request_payload'  => $payload,
                'provider_response_payload' => $body,
                'status'                    => 'pending',
            ]);

            if (!$response->successful() || ($body['status'] ?? '') === 'REJECTED') {
                $reason = $body['rejectionReason']['rejectionCode'] ?? 'Unknown rejection';
                $this->refundWallet($withdrawal, $reason);
                throw new \RuntimeException("Withdrawal rejected: {$reason}");
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->refundWallet($withdrawal, 'Connection timeout: ' . $e->getMessage());
            throw new \RuntimeException('Could not connect to payment provider. Please try again.');
        }

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'withdrawal.initiated',
            'entity_type' => 'Withdrawal',
            'entity_id'   => $withdrawal->id,
            'new_values'  => [
                'reference' => $withdrawal->reference,
                'amount'    => $amount,
                'currency'  => $currency,
                'operator'  => $mobileOperator,
                'provider'  => 'pawapay',
            ],
        ]);

        return $withdrawal->fresh();
    }

    /**
     * Handle incoming webhook from PawaPay or MTN MoMo.
     * Lookup is by provider_reference — works for both providers.
     * CRITICAL: Must be idempotent.
     */
    public function handleWebhook(array $payload): void
    {
        // PawaPay sends payoutId; MTN sends externalId
        $providerReference = $payload['payoutId'] ?? $payload['externalId'] ?? null;
        $status            = $payload['status'] ?? null;

        if (!$providerReference || !$status) {
            Log::warning('[WithdrawalService] Invalid webhook payload', $payload);
            return;
        }

        $withdrawal = Withdrawal::where('provider_reference', $providerReference)
            ->orWhere('reference', $providerReference)
            ->first();

        if (!$withdrawal) {
            Log::error('[WithdrawalService] Withdrawal not found for provider reference', [
                'provider_reference' => $providerReference,
            ]);
            return;
        }

        if ($withdrawal->isCompleted()) {
            Log::info('[WithdrawalService] Webhook ignored — already completed', [
                'provider_reference' => $providerReference,
            ]);
            return;
        }

        $withdrawal->update(['provider_webhook_payload' => $payload]);

        $completedStatuses = ['COMPLETED', 'SUCCESSFUL'];
        $failedStatuses    = ['FAILED', 'REJECTED', 'TIMED_OUT', 'EXPIRED'];

        if (in_array($status, $completedStatuses)) {
            $withdrawal->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => null,
                'payload'        => [
                    'type'      => 'withdrawal_completed',
                    'phone'     => $withdrawal->phone_number,
                    'amount'    => $withdrawal->amount,
                    'currency'  => $withdrawal->currency_code,
                    'reference' => $withdrawal->reference,
                ],
                'status' => 'pending',
            ]);

            AuditLog::create([
                'user_id'     => $withdrawal->user_id,
                'action'      => 'withdrawal.completed',
                'entity_type' => 'Withdrawal',
                'entity_id'   => $withdrawal->id,
                'new_values'  => [
                    'reference' => $withdrawal->reference,
                    'amount'    => $withdrawal->amount,
                    'currency'  => $withdrawal->currency_code,
                    'provider'  => $withdrawal->provider,
                ],
            ]);

            Log::info('[WithdrawalService] Withdrawal completed', [
                'reference' => $withdrawal->reference,
                'provider'  => $withdrawal->provider,
            ]);

        } elseif (in_array($status, $failedStatuses)) {
            $reason = $payload['rejectionReason']['rejectionCode'] ?? $status;
            $this->refundWallet($withdrawal, $reason);

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => null,
                'payload'        => [
                    'type'      => 'withdrawal_failed',
                    'phone'     => $withdrawal->phone_number,
                    'amount'    => $withdrawal->amount,
                    'currency'  => $withdrawal->currency_code,
                    'reference' => $withdrawal->reference,
                    'reason'    => $reason,
                ],
                'status' => 'pending',
            ]);

            Log::warning('[WithdrawalService] Withdrawal failed via webhook', [
                'reference' => $withdrawal->reference,
                'reason'    => $reason,
                'provider'  => $withdrawal->provider,
            ]);
        }
    }

    public function refundPendingStuck(Withdrawal $withdrawal): void
    {
        if ($withdrawal->status !== 'pending') {
            throw new \RuntimeException(
                "Withdrawal {$withdrawal->reference} is not in pending state."
            );
        }
        $this->refundWallet(
            $withdrawal,
            'Auto-recovery: withdrawal stuck in pending state for over 60 minutes — no webhook received'
        );
    }

    public function refundStuck(Withdrawal $withdrawal): void
    {
        if ($withdrawal->status !== 'initiated') {
            throw new \RuntimeException(
                "Withdrawal {$withdrawal->reference} is not in initiated state."
            );
        }
        $this->refundWallet(
            $withdrawal,
            'Auto-recovery: withdrawal stuck in initiated state for over 15 minutes'
        );
    }

    private function refundWallet(Withdrawal $withdrawal, string $reason): void
    {
        DB::transaction(function () use ($withdrawal, $reason) {
            $userAccount = Account::where('owner_id', $withdrawal->user_id)
                ->where('owner_type', User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $withdrawal->currency_code)
                ->lockForUpdate()
                ->firstOrFail();

            $systemAccount = Account::where('code', "{$withdrawal->currency_code}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

            app(LedgerService::class)->post(
                reference:   "WDR-REFUND-{$withdrawal->reference}",
                type:        'adjustment',
                currency:    $withdrawal->currency_code,
                entries: [
                    [
                        'account_id'  => $systemAccount->id,
                        'type'        => 'debit',
                        'amount'      => $withdrawal->amount,
                        'description' => "Withdrawal refund: {$withdrawal->reference}",
                    ],
                    [
                        'account_id'  => $userAccount->id,
                        'type'        => 'credit',
                        'amount'      => $withdrawal->amount,
                        'description' => "Withdrawal refunded: {$withdrawal->reference}",
                    ],
                ],
                description: "Withdrawal refund: {$withdrawal->reference}"
            );

            $withdrawal->update([
                'status'         => 'failed',
                'failure_reason' => $reason,
                'failed_at'      => now(),
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
