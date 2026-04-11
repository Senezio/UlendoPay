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

    // Pawapay payout correspondent codes — verified against /v1/availability
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

    /**
     * Initiate a withdrawal — pushes funds to user's mobile money wallet.
     *
     * @throws \RuntimeException
     */
    public function initiate(
        User   $user,
        string $phoneNumber,
        string $mobileOperator,
        float  $amount
    ): Withdrawal {
        // Resolve active wallet
        $wallet = $user->wallets()->where('status', 'active')->first();

        if (!$wallet) {
            throw new \RuntimeException("No active wallet found for user {$user->id}.");
        }

        $currency = $wallet->currency_code;

        if ($amount < 1) {
            throw new \RuntimeException("Minimum withdrawal amount is 1 {$currency}.");
        }

        // Check sufficient balance
        $userAccount = Account::where('code', "USR-{$user->id}-{$currency}")->first();

        if (!$userAccount) {
            throw new \RuntimeException("Wallet account not found.");
        }

        $balance = (float) ($userAccount->balance?->balance ?? 0);

        if ($amount > $balance) {
            throw new \RuntimeException(
                "Insufficient balance. Available: {$currency} " . number_format($balance, 2)
            );
        }

        // Resolve Pawapay correspondent
        $correspondentKey = "{$currency}:{$mobileOperator}";
        $correspondent    = $this->correspondentMap[$correspondentKey] ?? null;

        if (!$correspondent) {
            throw new \RuntimeException(
                "Mobile operator {$mobileOperator} is not supported for {$currency}. " .
                "Available operators: " . $this->getAvailableOperators($currency)
            );
        }

        $countryCode      = $user->country_code ?? $this->currencyToCountry($currency);
        $pawapayPayoutId  = (string) Str::uuid();

        // Debit wallet atomically before calling Pawapay
        $withdrawal = DB::transaction(function () use (
            $user, $wallet, $amount, $currency, $phoneNumber,
            $mobileOperator, $countryCode, $correspondent, $pawapayPayoutId
        ) {
            $userAccount = Account::where('code', "USR-{$user->id}-{$currency}")
                ->lockForUpdate()
                ->firstOrFail();

            $systemAccount = Account::where('code', "{$currency}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

            // Create withdrawal record
            $withdrawal = Withdrawal::create([
                'reference'         => Withdrawal::generateReference(),
                'user_id'           => $user->id,
                'wallet_id'         => $wallet->id,
                'amount'            => $amount,
                'currency_code'     => $currency,
                'phone_number'      => $phoneNumber,
                'mobile_operator'   => $mobileOperator,
                'country_code'      => $countryCode,
                'correspondent'     => $correspondent,
                'pawapay_payout_id' => $pawapayPayoutId,
                'status'            => 'initiated',
                'initiated_at'      => now(),
            ]);

            // Debit user wallet via LedgerService
            app(LedgerService::class)->post(
                reference:   "WDR-{$withdrawal->reference}",
                type:        'adjustment',
                currency:    $currency,
                entries:     [
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

        // Call Pawapay Payouts API
        $payload = [
            'payoutId'             => $pawapayPayoutId,
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

            Log::info('[WithdrawalService] Pawapay payout initiated', [
                'reference'        => $withdrawal->reference,
                'pawapay_payout_id' => $pawapayPayoutId,
                'http_status'      => $response->status(),
                'body'             => $body,
            ]);

            $withdrawal->update([
                'pawapay_request_payload'  => $payload,
                'pawapay_response_payload' => $body,
                'status'                   => 'pending',
            ]);

            if (!$response->successful() || ($body['status'] ?? '') === 'REJECTED') {
                $reason = $body['rejectionReason']['rejectionCode'] ?? 'Unknown rejection';

                // Refund wallet on rejection
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
            ],
        ]);

        return $withdrawal->fresh();
    }

    /**
     * Handle incoming Pawapay webhook for payout status update.
     * CRITICAL: Must be idempotent.
     */
    public function handleWebhook(array $payload): void
    {
        $payoutId = $payload['payoutId'] ?? null;
        $status   = $payload['status'] ?? null;

        if (!$payoutId || !$status) {
            Log::warning('[WithdrawalService] Invalid webhook payload', $payload);
            return;
        }

        $withdrawal = Withdrawal::where('pawapay_payout_id', $payoutId)
            ->orWhere('reference', $payoutId)
            ->first();

        if (!$withdrawal) {
            Log::error('[WithdrawalService] Withdrawal not found for payout', [
                'payoutId' => $payoutId,
            ]);
            return;
        }

        if ($withdrawal->isCompleted()) {
            Log::info('[WithdrawalService] Webhook ignored — already completed', [
                'payoutId' => $payoutId,
            ]);
            return;
        }

        $withdrawal->update(['pawapay_webhook_payload' => $payload]);

        if ($status === 'COMPLETED') {
            $withdrawal->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => null,
                'payload'        => [
                    'type'      => 'withdrawal_completed',
                    'user_id'   => $withdrawal->user_id,
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
                ],
            ]);

            Log::info('[WithdrawalService] Withdrawal completed', [
                'reference' => $withdrawal->reference,
                'amount'    => $withdrawal->amount,
                'currency'  => $withdrawal->currency_code,
            ]);

        } elseif (in_array($status, ['FAILED', 'REJECTED', 'TIMED_OUT', 'EXPIRED'])) {
            $reason = $payload['rejectionReason']['rejectionCode'] ?? $status;
            $this->refundWallet($withdrawal, $reason);

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => null,
                'payload'        => [
                    'type'      => 'withdrawal_failed',
                    'user_id'   => $withdrawal->user_id,
                    'phone'     => $withdrawal->phone_number,
                    'amount'    => $withdrawal->amount,
                    'currency'  => $withdrawal->currency_code,
                    'reference' => $withdrawal->reference,
                ],
                'status' => 'pending',
            ]);

            Log::warning('[WithdrawalService] Withdrawal failed via webhook', [
                'reference' => $withdrawal->reference,
                'reason'    => $reason,
            ]);
        }
    }

    /**
     * Refund wallet when withdrawal fails or is rejected.
     * Reverses the debit posted during initiation.
     */
    private function refundWallet(Withdrawal $withdrawal, string $reason): void
    {
        DB::transaction(function () use ($withdrawal, $reason) {
            $userAccount = Account::where('code', "USR-{$withdrawal->user_id}-{$withdrawal->currency_code}")
                ->lockForUpdate()
                ->firstOrFail();

            $systemAccount = Account::where('code', "{$withdrawal->currency_code}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

            app(LedgerService::class)->post(
                reference:   "WDR-REFUND-{$withdrawal->reference}",
                type:        'adjustment',
                currency:    $withdrawal->currency_code,
                entries:     [
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
