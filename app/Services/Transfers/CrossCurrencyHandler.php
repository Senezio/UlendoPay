<?php

namespace App\Services\Transfers;

use App\Models\Account;
use App\Models\OutboxEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Transfers\Contracts\TransferHandlerInterface;
use Illuminate\Support\Carbon;

/**
 * Handles cross-currency (FX) transfers.
 * Funds are split into escrow, fee, and guarantee accounts.
 * An internal_settlement outbox event is queued for async processing.
 * Transfer does NOT complete immediately.
 */
class CrossCurrencyHandler implements TransferHandlerInterface
{
    public function __construct(private LedgerService $ledger) {}

    public function supports(TransactionContext $ctx): bool
    {
        return ! $ctx->isSameCurrency;
    }

    public function handle(TransactionContext $ctx): Transaction
    {
        $senderAccount = Account::where('owner_id', $ctx->sender->id)
            ->where('owner_type', User::class)
            ->where('type', 'user_wallet')
            ->where('currency_code', $ctx->sendCurrency)
            ->firstOrFail();

        $escrowAccount = Account::where('type', 'escrow')
            ->where('currency_code', $ctx->sendCurrency)
            ->firstOrFail();

        $feeAccount = Account::where('type', 'fee')
            ->where('currency_code', $ctx->sendCurrency)
            ->firstOrFail();

        $guaranteeAccount = Account::where('type', 'guarantee')
            ->where('corridor', "{$ctx->sendCurrency}-{$ctx->receiveCurrency}")
            ->where('currency_code', $ctx->sendCurrency)
            ->firstOrFail();

        $transaction = Transaction::create([
            'reference_number'       => $ctx->reference,
            'sender_id'              => $ctx->sender->id,
            'recipient_id'           => $ctx->recipient->id,
            'rate_lock_id'           => $ctx->rateLock->id,
            'send_amount'            => $ctx->sendAmount,
            'send_currency'          => $ctx->sendCurrency,
            'receive_amount'         => $ctx->receiveAmount,
            'receive_currency'       => $ctx->receiveCurrency,
            'locked_rate'            => $ctx->lockedRate,
            'fee_amount'             => $ctx->feeAmount,
            'guarantee_contribution' => $ctx->guaranteeAmount,
            'status'                 => 'initiated',
        ]);

        $group = $this->ledger->post(
            reference:   "TXN-{$ctx->reference}-INIT",
            type:        'transfer_initiation',
            currency:    $ctx->sendCurrency,
            entries: [
                [
                    'account_id'  => $senderAccount->id,
                    'type'        => 'debit',
                    'amount'      => $ctx->sendAmount,
                    'description' => "Transfer initiation {$ctx->reference}",
                ],
                [
                    'account_id'  => $escrowAccount->id,
                    'type'        => 'credit',
                    'amount'      => $ctx->escrowAmount,
                    'description' => "Escrow for {$ctx->reference}",
                ],
                [
                    'account_id'  => $feeAccount->id,
                    'type'        => 'credit',
                    'amount'      => $ctx->feeAmount,
                    'description' => "Fee for {$ctx->reference}",
                ],
                [
                    'account_id'  => $guaranteeAccount->id,
                    'type'        => 'credit',
                    'amount'      => $ctx->guaranteeAmount,
                    'description' => "Guarantee contribution for {$ctx->reference}",
                ],
            ],
            description: "Initiation of transfer {$ctx->reference}"
        );

        $transaction->update([
            'journal_entry_group_id' => $group->id,
            'status'                 => 'escrowed',
            'escrowed_at'            => Carbon::now(),
        ]);

        $ctx->rateLock->update(['status' => 'used', 'used_at' => Carbon::now()]);

        OutboxEvent::create([
            'event_type'     => 'internal_settlement',
            'transaction_id' => $transaction->id,
            'payload'        => [
                'transaction_id' => $transaction->id,
                'reference'      => $ctx->reference,
            ],
            'status'          => 'pending',
            'next_attempt_at' => Carbon::now(),
            'max_attempts'    => 5,
        ]);

        OutboxEvent::create([
            'event_type'     => 'sms_notification',
            'transaction_id' => $transaction->id,
            'payload'        => [
                'type'      => 'transfer_sent',
                'phone'     => $ctx->sender->phone,
                'amount'    => $ctx->sendAmount,
                'currency'  => $ctx->sendCurrency,
                'reference' => $ctx->reference,
            ],
            'status'          => 'pending',
            'next_attempt_at' => Carbon::now(),
        ]);

        return $transaction;
    }
}
