<?php

namespace App\Services;

use App\Models\TopUp;
use App\Models\Withdrawal;
use App\Models\User;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MtnMomoService
{
    private string $baseUrl;
    private string $environment;
    private string $callbackUrl;

    private string $collectionSubscriptionKey;
    private string $collectionUserId;
    private string $collectionApiKey;

    private string $disbursementSubscriptionKey;
    private string $disbursementUserId;
    private string $disbursementApiKey;

    private array $currencyMap = [
        'ZAR' => 'ZAF',
        'GHS' => 'GHA',
        'UGX' => 'UGA',
        'RWF' => 'RWA',
        'ZMW' => 'ZMB',
        'XAF' => 'CMR',
        'XOF' => 'BEN',
    ];

    public function __construct()
    {
        $this->baseUrl     = config('services.mtn_momo.base_url', 'https://sandbox.momodeveloper.mtn.com');
        $this->environment = config('services.mtn_momo.environment', 'sandbox');
        $this->callbackUrl = config('services.mtn_momo.callback_url', '');

        $this->collectionSubscriptionKey  = config('services.mtn_momo.collection.subscription_key', '');
        $this->collectionUserId           = config('services.mtn_momo.collection.user_id', '');
        $this->collectionApiKey           = config('services.mtn_momo.collection.api_key', '');

        $this->disbursementSubscriptionKey = config('services.mtn_momo.disbursement.subscription_key', '');
        $this->disbursementUserId          = config('services.mtn_momo.disbursement.user_id', '');
        $this->disbursementApiKey          = config('services.mtn_momo.disbursement.api_key', '');

        if (empty($this->collectionSubscriptionKey) || empty($this->disbursementSubscriptionKey)) {
            throw new \RuntimeException('MTN MoMo credentials are not configured.');
        }
    }

    public function supportsCurrency(string $currency): bool
    {
        return isset($this->currencyMap[$currency]);
    }

    public function getSupportedOperators(string $currency): array
    {
        return isset($this->currencyMap[$currency]) ? ['MTN'] : [];
    }

    /**
     * Initiate a top-up via MTN MoMo Collections (RequestToPay).
     *
     * Returns the MTN reference UUID so the caller can store it
     * on the TopUp record as provider_reference.
     * This service never mutates records — the caller owns the record.
     *
     * @throws \RuntimeException on API failure
     */
    public function initiateTopUp(
        User   $user,
        string $phoneNumber,
        float  $amount,
        string $currency,
        string $externalReference
    ): string {
        $token     = $this->getCollectionToken();
        $reference = (string) Str::uuid();

        $payload = [
            'amount'      => (string) $amount,
            'currency'    => $this->environment === 'sandbox' ? 'EUR' : $currency,
            'externalId'  => $externalReference,
            'payer'       => [
                'partyIdType' => 'MSISDN',
                'partyId'     => ltrim($phoneNumber, '+'),
            ],
            'payerMessage' => 'Ulendo Pay wallet topup',
            'payeeNote'    => 'Ulendo Pay topup',
        ];

        $response = Http::withToken($token)
            ->withHeaders([
                'X-Reference-Id'            => $reference,
                'X-Target-Environment'      => $this->environment,
                'Ocp-Apim-Subscription-Key' => $this->collectionSubscriptionKey,
            ])
            ->post("{$this->baseUrl}/collection/v1_0/requesttopay", $payload);

        Log::info('[MtnMomoService] RequestToPay sent', [
            'external_reference' => $externalReference,
            'momo_reference'     => $reference,
            'http_status'        => $response->status(),
        ]);

        if ($response->status() !== 202) {
            throw new \RuntimeException(
                'MTN MoMo payment request failed: ' . $response->body()
            );
        }

        return $reference;
    }

    /**
     * Initiate a withdrawal via MTN MoMo Disbursements (Transfer).
     *
     * Returns the MTN reference UUID so the caller can store it
     * on the Withdrawal record as provider_reference.
     * This service never mutates records — the caller owns the record.
     *
     * @throws \RuntimeException on API failure
     */
    public function initiateWithdrawal(
        User   $user,
        string $phoneNumber,
        float  $amount,
        string $currency,
        string $externalReference
    ): string {
        $token     = $this->getDisbursementToken();
        $reference = (string) Str::uuid();

        $payload = [
            'amount'      => (string) $amount,
            'currency'    => $this->environment === 'sandbox' ? 'EUR' : $currency,
            'externalId'  => $externalReference,
            'payee'       => [
                'partyIdType' => 'MSISDN',
                'partyId'     => ltrim($phoneNumber, '+'),
            ],
            'payerMessage' => 'Ulendo Pay withdrawal',
            'payeeNote'    => 'Ulendo Pay withdrawal',
        ];

        $response = Http::withToken($token)
            ->withHeaders([
                'X-Reference-Id'            => $reference,
                'X-Target-Environment'      => $this->environment,
                'Ocp-Apim-Subscription-Key' => $this->disbursementSubscriptionKey,
            ])
            ->post("{$this->baseUrl}/disbursement/v1_0/transfer", $payload);

        Log::info('[MtnMomoService] Transfer sent', [
            'external_reference' => $externalReference,
            'momo_reference'     => $reference,
            'http_status'        => $response->status(),
        ]);

        if ($response->status() !== 202) {
            throw new \RuntimeException(
                'MTN MoMo transfer failed: ' . $response->body()
            );
        }

        return $reference;
    }

    public function getTopUpStatus(string $momoReference): array
    {
        $token = $this->getCollectionToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'X-Target-Environment'      => $this->environment,
                'Ocp-Apim-Subscription-Key' => $this->collectionSubscriptionKey,
            ])
            ->get("{$this->baseUrl}/collection/v1_0/requesttopay/{$momoReference}");

        return $response->json() ?? [];
    }

    public function getWithdrawalStatus(string $momoReference): array
    {
        $token = $this->getDisbursementToken();

        $response = Http::withToken($token)
            ->withHeaders([
                'X-Target-Environment'      => $this->environment,
                'Ocp-Apim-Subscription-Key' => $this->disbursementSubscriptionKey,
            ])
            ->get("{$this->baseUrl}/disbursement/v1_0/transfer/{$momoReference}");

        return $response->json() ?? [];
    }

    /**
     * Credit wallet after successful MTN MoMo collection.
     * Called from webhook handler in TopUpService.
     *
     * Accounting: EQUITY is debited (internal source of funds) and
     * the user wallet is credited. The POOL is not touched here —
     * it only moves when real money physically arrives/leaves via the provider.
     */
    public function creditWallet(TopUp $topUp): void
    {
        DB::transaction(function () use ($topUp) {
            $userAccount = Account::where('owner_id', $topUp->user_id)
                ->where('owner_type', User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $topUp->currency_code)
                ->lockForUpdate()
                ->firstOrFail();

            $systemAccount = Account::where('code', $topUp->currency_code . '-EQUITY')
                ->lockForUpdate()
                ->firstOrFail();

            app(LedgerService::class)->post(
                reference:   "TOPUP-{$topUp->reference}",
                type:        'adjustment',
                currency:    $topUp->currency_code,
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
                description: "MTN MoMo top-up: {$topUp->reference}"
            );

            $topUp->update(['status' => 'completed', 'completed_at' => now()]);

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

            Log::info('[MtnMomoService] Wallet credited', ['reference' => $topUp->reference]);
        });
    }

    /**
     * Refund wallet after failed MTN MoMo withdrawal.
     * Called from webhook handler in WithdrawalService.
     *
     * Accounting: POOL is debited (held funds released back) and
     * the user wallet is credited. This is the exact reverse of
     * the withdrawal initiation entry.
     */
    public function refundWallet(Withdrawal $withdrawal, string $reason): void
    {
        DB::transaction(function () use ($withdrawal, $reason) {
            $userAccount = Account::where('owner_id', $withdrawal->user_id)
                ->where('owner_type', User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $withdrawal->currency_code)
                ->lockForUpdate()
                ->firstOrFail();

            $systemAccount = Account::where('code', $withdrawal->currency_code . '-POOL')
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
                description: "MTN MoMo withdrawal refund: {$withdrawal->reference}"
            );

            $withdrawal->update([
                'status'         => 'failed',
                'failure_reason' => $reason,
                'failed_at'      => now(),
            ]);
        });
    }

    private function getCollectionToken(): string
    {
        $credentials = base64_encode("{$this->collectionUserId}:{$this->collectionApiKey}");

        $response = Http::withHeaders([
            'Authorization'             => "Basic {$credentials}",
            'Ocp-Apim-Subscription-Key' => $this->collectionSubscriptionKey,
        ])->post("{$this->baseUrl}/collection/token/");

        if (!$response->successful()) {
            throw new \RuntimeException(
                'Failed to get MTN MoMo collection token: ' . $response->body()
            );
        }

        return $response->json('access_token');
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
