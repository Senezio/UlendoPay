<?php

namespace App\Services\Withdrawals;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\LedgerService;
use App\Services\MtnMomoService;
use App\Services\Withdrawals\Contracts\WithdrawalHandlerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MobileMoneyWithdrawalHandler implements WithdrawalHandlerInterface
{
    public function __construct(
        private LedgerService $ledger,
        private MtnMomoService $mtnMomo,
    ) {}

    public function supports(WithdrawalContext $context): bool
    {
        return $context->isMobileMoney();
    }

    public function handle(WithdrawalContext $context): Withdrawal
    {
        if ($this->mtnMomo->supportsCurrency($context->currency)) {
            return $this->handleMtnMomo($context);
        }

        return $this->handlePawapay($context);
    }

    private function handleMtnMomo(WithdrawalContext $context): Withdrawal
    {
        $wallet = $context->user->wallets()->where('status', 'active')->firstOrFail();

        $withdrawal = DB::transaction(function () use ($context, $wallet) {
            $userAccount = Account::where('owner_id', $context->user->id)
                ->where('owner_type', User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $context->currency)
                ->lockForUpdate()
                ->firstOrFail();

            $poolAccount = Account::where('code', "{$context->currency}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

            $withdrawalRecord = Withdrawal::create([
                'reference'       => Withdrawal::generateReference(),
                'user_id'         => $context->user->id,
                'wallet_id'       => $wallet->id,
                'amount'          => $context->amount,
                'currency_code'   => $context->currency,
                'phone_number'    => $context->phoneNumber,
                'mobile_operator' => $context->mobileOperator,
                'country_code'    => $context->countryCode,
                'provider'        => 'mtnmomo',
                'correspondent'   => 'MTN_MOMO',
                'status'          => 'initiated',
                'initiated_at'    => now(),
            ]);

            $this->ledger->post(
                reference:   "WDR-{$withdrawalRecord->reference}",
                type:        'adjustment',
                currency:    $context->currency,
                entries: [
                    [
                        'account_id'  => $userAccount->id,
                        'type'        => 'debit',
                        'amount'      => $context->amount,
                        'description' => "Withdrawal: {$withdrawalRecord->reference}",
                    ],
                    [
                        'account_id'  => $poolAccount->id,
                        'type'        => 'credit',
                        'amount'      => $context->amount,
                        'description' => "Withdrawal held: {$withdrawalRecord->reference}",
                    ],
                ],
                description: "MTN MoMo withdrawal: {$withdrawalRecord->reference}"
            );

            return $withdrawalRecord;
        });

        $providerResponse = $this->mtnMomo->initiateWithdrawal(
            user:              $context->user,
            phoneNumber:       $context->phoneNumber,
            amount:            $context->amount,
            currency:          $context->currency,
            externalReference: $withdrawal->reference
        );

        $withdrawal->update([
            'provider_reference' => $providerResponse,
            'status'             => 'pending',
        ]);

        return $withdrawal->fresh();
    }

    private function handlePawapay(WithdrawalContext $context): Withdrawal
    {
        $correspondents = config('services.pawapay.correspondents', []);
        $lookupKey = "{$context->currency}:{$context->mobileOperator}";
        $correspondent = $correspondents[$lookupKey] ?? null;

        if (!$correspondent) {
            $available = collect($correspondents)
                ->keys()
                ->filter(fn($key) => str_starts_with($key, "{$context->currency}:"))
                ->map(fn($key) => explode(':', $key)[1])
                ->implode(', ');

            throw new \RuntimeException(
                "Mobile operator {$context->mobileOperator} is not supported for {$context->currency}. " .
                "Available operators: {$available}"
            );
        }

        $payoutId = (string) Str::uuid();
        $baseUrl = config('services.pawapay.base_url');
        $apiToken = config('services.pawapay.api_token');
        $timeout = config('services.pawapay.timeout', 30);
        $wallet = $context->user->wallets()->where('status', 'active')->firstOrFail();

        $withdrawal = DB::transaction(function () use ($context, $wallet, $payoutId, $correspondent) {
            $userAccount = Account::where('owner_id', $context->user->id)
                ->where('owner_type', User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $context->currency)
                ->lockForUpdate()
                ->firstOrFail();

            $poolAccount = Account::where('code', "{$context->currency}-POOL")
                ->lockForUpdate()
                ->firstOrFail();

            $withdrawalRecord = Withdrawal::create([
                'reference'          => Withdrawal::generateReference(),
                'user_id'            => $context->user->id,
                'wallet_id'          => $wallet->id,
                'amount'             => $context->amount,
                'currency_code'      => $context->currency,
                'phone_number'       => $context->phoneNumber,
                'mobile_operator'    => $context->mobileOperator,
                'country_code'       => $context->countryCode,
                'provider'           => 'pawapay',
                'provider_reference' => $payoutId,
                'correspondent'      => $correspondent,
                'status'             => 'initiated',
                'initiated_at'       => now(),
            ]);

            $this->ledger->post(
                reference:   "WDR-{$withdrawalRecord->reference}",
                type:        'adjustment',
                currency:    $context->currency,
                entries: [
                    [
                        'account_id'  => $userAccount->id,
                        'type'        => 'debit',
                        'amount'      => $context->amount,
                        'description' => "Withdrawal: {$withdrawalRecord->reference}",
                    ],
                    [
                        'account_id'  => $poolAccount->id,
                        'type'        => 'credit',
                        'amount'      => $context->amount,
                        'description' => "Withdrawal held: {$withdrawalRecord->reference}",
                    ],
                ],
                description: "Mobile money withdrawal: {$withdrawalRecord->reference}"
            );

            return $withdrawalRecord;
        });

        $payload = [
            'payoutId'             => $payoutId,
            'amount'               => number_format($context->amount, 2, '.', ''),
            'currency'             => $context->currency,
            'country'              => $context->countryCode,
            'correspondent'        => $correspondent,
            'recipient'            => [
                'type'    => 'MSISDN',
                'address' => ['value' => ltrim($context->phoneNumber, '+')],
            ],
            'customerTimestamp'    => now()->toIso8601String(),
            'statementDescription' => config('app.name') . ' withdrawal',
        ];

        try {
            $response = Http::withToken($apiToken)
                ->timeout($timeout)
                ->post("{$baseUrl}/payouts", $payload);

            $responseData = $response->json() ?? [];

            Log::info('[MobileMoneyWithdrawalHandler] PawaPay payout initiated', [
                'reference'          => $withdrawal->reference,
                'provider_reference' => $payoutId,
                'http_status'        => $response->status(),
            ]);

            $withdrawal->update([
                'provider_request_payload'  => $payload,
                'provider_response_payload' => $responseData,
                'status'                    => 'pending',
            ]);

            if (!$response->successful() || ($responseData['status'] ?? '') === 'REJECTED') {
                $reason = $responseData['rejectionReason']['rejectionCode'] ?? 'Unknown rejection';
                app(WithdrawalRefundHandler::class)->refundWallet($withdrawal, $reason);
                throw new \RuntimeException("Withdrawal rejected: {$reason}");
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            app(WithdrawalRefundHandler::class)->refundWallet(
                $withdrawal,
                'Connection timeout: ' . $e->getMessage()
            );
            throw new \RuntimeException('Could not connect to payment provider. Please try again.');
        }

        AuditLog::create([
            'user_id'     => $context->user->id,
            'action'      => 'withdrawal.initiated',
            'entity_type' => Withdrawal::class,
            'entity_id'   => $withdrawal->id,
            'new_values'  => [
                'reference' => $withdrawal->reference,
                'amount'    => $context->amount,
                'currency'  => $context->currency,
                'operator'  => $context->mobileOperator,
                'provider'  => 'pawapay',
            ],
        ]);

        return $withdrawal->fresh();
    }
}
