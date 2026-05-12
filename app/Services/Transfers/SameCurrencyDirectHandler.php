<?php

namespace App\Services\Transfers;

use App\Models\Account;
use App\Models\OutboxEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\TierService;
use App\Services\Transfers\Contracts\TransferHandlerInterface;
use Illuminate\Support\Carbon;

/**
 * Handles same-currency transfers where the recipient
 * is already a registered UlendoPay user with a wallet.
 * Transfer completes immediately — no escrow needed.
 */
class SameCurrencyDirectHandler implements TransferHandlerInterface
{
    public function __construct(
        private LedgerService $ledger,
        private TierService   $tier,
    ) {}

    public function supports(TransactionContext $ctx): bool
    {
        if (! $ctx->isSameCurrency) {
            return false;
        }

        if ($ctx->recipient->payment_method === 'wallet_transfer') {
            return false;
        }

        return $ctx->resolvedRecipientUser !== null;
    }

    public function handle(TransactionContext $ctx): Transaction
    {
        $recipientUser = $ctx->resolvedRecipientUser;

        if (! $recipientUser) {
            throw new \RuntimeException('Resolved recipient user is missing in context.');
        }

        $senderAccount = Account::where('owner_id', $ctx->sender->id)
            ->where('owner_type', User::class)
            ->where('type', 'user_wallet')
            ->where('currency_code', $ctx->sendCurrency)
            ->firstOrFail();

        $recipientAccount = Account::where('owner_id', $recipientUser->id)
            ->where('owner_type', User::class)
            ->where('type', 'user_wallet')
            ->where('currency_code', $ctx->receiveCurrency)
            ->first();

        if (! $recipientAccount) {
            throw new \RuntimeException(
                "Recipient does not have a {$ctx->receiveCurrency} wallet."
            );
        }

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
            reference:   "TXN-{$ctx->reference}-DIRECT",
            type:        'transfer_initiation',
            currency:    $ctx->sendCurrency,
            entries: [
                [
                    'account_id'  => $senderAccount->id,
                    'type'        => 'debit',
                    'amount'      => $ctx->sendAmount,
                    'description' => "Direct transfer {$ctx->reference}",
                ],
                [
                    'account_id'  => $recipientAccount->id,
                    'type'        => 'credit',
                    'amount'      => $ctx->receiveAmount,
                    'description' => "Received transfer {$ctx->reference}",
                ],
            ],
            description: "Same-currency transfer {$ctx->reference}"
        );

        $transaction->update([
            'journal_entry_group_id' => $group->id,
            'status'                 => 'completed',
            'escrowed_at'            => Carbon::now(),
            'completed_at'           => Carbon::now(),
        ]);

        $ctx->rateLock->update(['status' => 'used', 'used_at' => Carbon::now()]);

        $this->tier->qualifyReferral($ctx->sender);

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

        return $transaction;
    }
}
