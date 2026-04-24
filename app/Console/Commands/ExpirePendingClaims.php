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
            ->with(['transaction.sender'])
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No expired claims.');
            return self::SUCCESS;
        }

        $this->info("Processing {$expired->count()} expired claim(s)...");

        foreach ($expired as $claim) {
            try {
                DB::transaction(function () use ($claim, $ledger) {
                    $transaction  = $claim->transaction;
                    $reference    = $transaction->reference_number;

                    // Bug 1 fix: skip if transaction already refunded/completed
                    if (!in_array($transaction->status, ['pending_claim', 'escrowed'])) {
                        $this->warn("Skipping claim {$claim->id} — transaction {$reference} already in status: {$transaction->status}");
                        $claim->update(['status' => 'refunded', 'refunded_at' => now()]);
                        return;
                    }

                    // Bug 2 fix: use send_currency (where money actually is),
                    // not the claim's currency_code which is the receive currency
                    $sendCurrency = $transaction->send_currency;
                    $sendAmount   = (float) $transaction->send_amount;
                    $feeAmount    = (float) $transaction->fee_amount;
                    $guarantee    = (float) $transaction->guarantee_contribution;

                    // For same-currency pending claims: money is in ESCROW-{sendCurrency}
                    // For cross-currency: money is split across escrow + fee + guarantee
                    $isSameCurrency = $transaction->send_currency === $transaction->receive_currency;

                    $escrowAccount = Account::where('type', 'escrow')
                        ->where('currency_code', $sendCurrency)
                        ->firstOrFail();

                    $senderAccount = Account::where('owner_id', $transaction->sender_id)
                        ->where('owner_type', \App\Models\User::class)
                        ->where('type', 'user_wallet')
                        ->where('currency_code', $sendCurrency)
                        ->firstOrFail();

                    if ($isSameCurrency) {
                        // Same-currency hold: full send_amount is in escrow
                        $ledger->post(
                            reference:   "TXN-{$reference}-EXPIRE",
                            type:        'adjustment',
                            currency:    $sendCurrency,
                            entries: [
                                [
                                    'account_id'  => $escrowAccount->id,
                                    'type'        => 'debit',
                                    'amount'      => $sendAmount,
                                    'description' => "Expired claim escrow release: {$reference}",
                                ],
                                [
                                    'account_id'  => $senderAccount->id,
                                    'type'        => 'credit',
                                    'amount'      => $sendAmount,
                                    'description' => "Refund — unclaimed transfer: {$reference}",
                                ],
                            ],
                            description: "Expired claim refund {$reference}"
                        );
                    } else {
                        // Cross-currency: escrowAmount is in escrow,
                        // fee is in fee account, guarantee is in guarantee account
                        $escrowAmount = $sendAmount - $feeAmount - $guarantee;

                        $feeAccount = Account::where('type', 'fee')
                            ->where('currency_code', $sendCurrency)
                            ->firstOrFail();

                        $guaranteeAccount = Account::where('type', 'guarantee')
                            ->where('corridor', "{$sendCurrency}-{$transaction->receive_currency}")
                            ->where('currency_code', $sendCurrency)
                            ->firstOrFail();

                        // Bug 3 fix: full refund including fee, matching RefundService behaviour
                        $ledger->post(
                            reference:   "TXN-{$reference}-EXPIRE",
                            type:        'adjustment',
                            currency:    $sendCurrency,
                            entries: [
                                [
                                    'account_id'  => $escrowAccount->id,
                                    'type'        => 'debit',
                                    'amount'      => $escrowAmount,
                                    'description' => "Expired claim escrow release: {$reference}",
                                ],
                                [
                                    'account_id'  => $feeAccount->id,
                                    'type'        => 'debit',
                                    'amount'      => $feeAmount,
                                    'description' => "Expired claim fee return: {$reference}",
                                ],
                                [
                                    'account_id'  => $guaranteeAccount->id,
                                    'type'        => 'debit',
                                    'amount'      => $guarantee,
                                    'description' => "Expired claim guarantee return: {$reference}",
                                ],
                                [
                                    'account_id'  => $senderAccount->id,
                                    'type'        => 'credit',
                                    'amount'      => $sendAmount,
                                    'description' => "Refund — unclaimed transfer: {$reference}",
                                ],
                            ],
                            description: "Expired claim refund {$reference}"
                        );
                    }

                    $claim->update([
                        'status'      => 'refunded',
                        'refunded_at' => now(),
                    ]);

                    $transaction->update([
                        'status'      => 'refunded',
                        'refunded_at' => now(),
                    ]);

                    OutboxEvent::create([
                        'event_type'     => 'sms_notification',
                        'transaction_id' => $claim->transaction_id,
                        'payload'        => [
                            'type'      => 'claim_expired_refund',
                            'phone'     => $transaction->sender->phone,
                            'amount'    => $sendAmount,
                            'currency'  => $sendCurrency,
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
