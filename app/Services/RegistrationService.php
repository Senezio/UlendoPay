<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\OutboxEvent;
use App\Models\PendingClaim;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LedgerService;
use App\Services\TierService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegistrationService
{
    public function __construct(
        private LedgerService $ledger,
        private TierService   $tierService,
    ) {}

    public function createUserWallet(User $user, ?string $referralCode = null): Wallet
    {
        $currency = $this->resolveCurrency($user->country_code);

        return DB::transaction(function () use ($user, $currency, $referralCode) {
            $account = Account::create([
                'code'           => $this->generateAccountCode(),
                'type'           => 'user_wallet',
                'currency_code'  => $currency,
                'owner_id'       => $user->id,
                'owner_type'     => User::class,
                'normal_balance' => 'credit',
                'is_active'      => true,
            ]);

            AccountBalance::create([
                'account_id'      => $account->id,
                'balance'         => 0,
                'currency_code'   => $currency,
                'last_updated_at' => now(),
            ]);

            $wallet = Wallet::create([
                'user_id'       => $user->id,
                'account_id'    => $account->id,
                'currency_code' => $currency,
                'status'        => 'active',
            ]);

            if ($referralCode) {
                try {
                    $this->tierService->applyReferral($user, $referralCode);
                } catch (\Throwable $e) {
                    Log::warning('[RegistrationService] Referral apply failed', [
                        'user_id'       => $user->id,
                        'referral_code' => $referralCode,
                        'error'         => $e->getMessage(),
                    ]);
                }
            }

            $this->tierService->generateReferralCode($user);

            return $wallet;
        });
    }

    public function releasePendingClaims(User $user): void
    {
        $phoneHash = hash('sha256', $user->phone);

        $claims = PendingClaim::where('recipient_phone_hash', $phoneHash)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->get();

        if ($claims->isEmpty()) return;

        foreach ($claims as $claim) {
            try {
                DB::transaction(function () use ($claim, $user) {
                    $recipientAccount = Account::where('owner_id', $user->id)
                        ->where('owner_type', User::class)
                        ->where('type', 'user_wallet')
                        ->where('currency_code', $claim->currency_code)
                        ->first();

                    if (!$recipientAccount) return;

                    $escrowAccount = Account::where('type', 'escrow')
                        ->where('currency_code', $claim->currency_code)
                        ->firstOrFail();

                    $reference = $claim->transaction->reference_number;

                    $this->ledger->post(
                        reference:   "TXN-{$reference}-CLAIM",
                        type:        'transfer_claim',
                        currency:    $claim->currency_code,
                        entries: [
                            [
                                'account_id'  => $escrowAccount->id,
                                'type'        => 'debit',
                                'amount'      => $claim->amount,
                                'description' => "Claim released: {$reference}",
                            ],
                            [
                                'account_id'  => $recipientAccount->id,
                                'type'        => 'credit',
                                'amount'      => $claim->amount,
                                'description' => "Claimed transfer: {$reference}",
                            ],
                        ],
                        description: "Claim release for {$reference}"
                    );

                    $claim->update([
                        'status'     => 'claimed',
                        'claimed_by' => $user->id,
                        'claimed_at' => now(),
                    ]);

                    $claim->transaction->update([
                        'status'       => 'completed',
                        'completed_at' => now(),
                    ]);

                    OutboxEvent::create([
                        'event_type'     => 'sms_notification',
                        'transaction_id' => $claim->transaction_id,
                        'payload'        => [
                            'type'      => 'claim_released',
                            'phone'     => $user->phone,
                            'amount'    => $claim->amount,
                            'currency'  => $claim->currency_code,
                            'reference' => $reference,
                        ],
                        'status'          => 'pending',
                        'next_attempt_at' => now(),
                    ]);
                });
            } catch (\Throwable $e) {
                Log::error('[RegistrationService] Failed to release pending claim', [
                    'claim_id' => $claim->id,
                    'user_id'  => $user->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveCurrency(string $countryCode): string
    {
        return match(strtoupper($countryCode)) {
            'MWI', 'MW' => 'MWK',
            'TZA', 'TZ' => 'TZS',
            'KEN', 'KE' => 'KES',
            'ZMB', 'ZM' => 'ZMW',
            'ZAF', 'ZA' => 'ZAR',
            'MOZ', 'MZ' => 'MZN',
            'BWA', 'BW' => 'BWP',
            'ETH', 'ET' => 'ETB',
            'MDG', 'MG' => 'MGA',
            default      => 'MWK',
        };
    }

    private function generateAccountCode(): string
    {
        do {
            $code = (string) random_int(1, 9)
                . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT)
                . random_int(1, 9);
        } while (Account::where('code', $code)->exists());

        return $code;
    }
}
