<?php

namespace App\Services\Transfers;

use App\Models\Account;
use App\Models\OutboxEvent;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Services\TierService;
use App\Services\Transfers\Contracts\TransferHandlerInterface;
use Illuminate\Support\Carbon;

/**
 * Handles wallet-to-wallet transfers.
 * Recipient is identified by wallet account number.
 * Transfer completes immediately — no escrow, no outbox settlement.
 */
class WalletTransferHandler implements TransferHandlerInterface
{
    public function __construct(
        private LedgerService $ledger,
        private TierService   $tier,
    ) {}

    public function supports(TransactionContext $ctx): bool
    {
        return $ctx->recipient->payment_method === 'wallet_transfer';
    }

    public function handle(TransactionContext $ctx): Transaction
    {
        $recipientAccount = Account::where('code', $ctx->recipient->wallet_account)
            ->where('type', 'user_wallet')
            ->where('is_active', true)
            ->first();

        if (! $recipientAccount) {
            throw new \RuntimeException(
                "Wallet account {$ctx->recipient->wallet_account} not found or inactive."
            );
        }

        if ($recipientAccount->currency_code !== $ctx->receiveCurrency) {
            throw new \RuntimeException(
                'Wallet account currency does not match the selected destination currency.'
            );
        }

        $senderAccount = Account::where('owner_id', $ctx->sender->id)
            ->where('owner_type', \App\Models\User::class)
            ->where('type', 'user_wallet')
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
            reference:   "TXN-{$ctx->reference}-WALLET",
            type:        'transfer_initiation',
            currency:    $ctx->sendCurrency,
            entries: [
                [
                    'account_id'  => $senderAccount->id,
                    'type'        => 'debit',
                    'amount'      => $ctx->sendAmount,
                    'description' => "Wallet transfer {$ctx->reference}",
                ],
                [
                    'account_id'  => $recipientAccount->id,
                    'type'        => 'credit',
                    'amount'      => $ctx->receiveAmount,
                    'description' => "Wallet transfer received {$ctx->reference}",
                ],
            ],
            description: "Wallet-to-wallet transfer {$ctx->reference}"
        );

        $transaction->update([
            'journal_entry_group_id' => $group->id,
            'status'                 => 'completed',
            'escrowed_at'            => Carbon::now(),
            'completed_at'           => Carbon::now(),
        ]);

        $ctx->rateLock->update(['status' => 'used', 'used_at' => Carbon::now()]);

        $this->tier->qualifyReferral($ctx->sender);

        $recipientUser = \App\Models\User::find($recipientAccount->owner_id);

        OutboxEvent::create([
            'event_type'     => 'sms_notification',
            'transaction_id' => $transaction->id,
            'payload'        => [
                'type'      => 'transfer_sent',
                'reference' => $ctx->reference,
                'amount'    => $ctx->sendAmount,
                'currency'  => $ctx->sendCurrency,
                'phone'     => $ctx->sender->phone,
            ],
            'status'          => 'pending',
            'next_attempt_at' => Carbon::now(),
        ]);

        if ($recipientUser) {
            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => $transaction->id,
                'payload'        => [
                    'type'      => 'transfer_received',
                    'reference' => $ctx->reference,
                    'amount'    => $ctx->receiveAmount,
                    'currency'  => $ctx->receiveCurrency,
                    'phone'     => $recipientUser->phone,
                ],
                'status'          => 'pending',
                'next_attempt_at' => Carbon::now(),
            ]);
        }

        return $transaction;
    }
}
