<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\OutboxEvent;
use App\Models\PendingClaim;
use App\Services\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpirePendingClaims extends Command
{
    protected $signature   = 'claims:expire';
    protected $description = 'Expire pending claims older than 48 hours and refund senders';

    public function handle(LedgerService $ledger): int
    {
        $expired = PendingClaim::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->with('transaction')
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No expired claims.');
            return self::SUCCESS;
        }

        $this->info("Processing {$expired->count()} expired claim(s)...");

        foreach ($expired as $claim) {
            try {
                DB::transaction(function () use ($claim, $ledger) {
                    $reference    = $claim->transaction->reference_number;
                    $sendCurrency = $claim->transaction->send_currency;
                    $sendAmount   = (float) $claim->transaction->send_amount;

                    $escrowAccount = Account::where('type', 'escrow')
                        ->where('currency_code', $sendCurrency)
                        ->firstOrFail();

                    $senderAccount = Account::where('owner_id', $claim->transaction->sender_id)
                        ->where('owner_type', \App\Models\User::class)
                        ->where('type', 'user_wallet')
                        ->where('currency_code', $sendCurrency)
                        ->firstOrFail();

                    $refundAmount = $sendAmount - (float) $claim->transaction->fee_amount;

                    $ledger->post(
                        reference:   "TXN-{$reference}-EXPIRE",
                        type:        'adjustment',
                        currency:    $sendCurrency,
                        entries: [
                            [
                                'account_id'  => $escrowAccount->id,
                                'type'        => 'debit',
                                'amount'      => $refundAmount,
                                'description' => "Expired claim refund: {$reference}",
                            ],
                            [
                                'account_id'  => $senderAccount->id,
                                'type'        => 'credit',
                                'amount'      => $refundAmount,
                                'description' => "Refund — unclaimed transfer: {$reference}",
                            ],
                        ],
                        description: "Expired claim refund {$reference}"
                    );

                    $claim->update([
                        'status'      => 'refunded',
                        'refunded_at' => now(),
                    ]);

                    $claim->transaction->update([
                        'status'      => 'refunded',
                        'refunded_at' => now(),
                    ]);

                    // Notify sender
                    OutboxEvent::create([
                        'event_type'     => 'sms_notification',
                        'transaction_id' => $claim->transaction_id,
                        'payload'        => [
                            'type'      => 'claim_expired_refund',
                            'phone'     => $claim->transaction->sender->phone,
                            'amount'    => $claim->amount,
                            'currency'  => $currency,
                            'reference' => $reference,
                        ],
                        'status'          => 'pending',
                        'next_attempt_at' => now(),
                    ]);
                });

                $this->info("✓ Claim {$claim->id} expired and refunded");
                Log::info('[ExpirePendingClaims] Claim refunded', ['claim_id' => $claim->id]);

            } catch (\Throwable $e) {
                $this->error("✗ Claim {$claim->id} failed: {$e->getMessage()}");
                Log::error('[ExpirePendingClaims] Failed', [
                    'claim_id' => $claim->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
