<?php

namespace App\Services\Transfers;

use App\Models\Account;
use App\Models\OutboxEvent;
use App\Models\PendingClaim;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Transfers\Contracts\TransferHandlerInterface;
use Illuminate\Support\Carbon;

/**
 * Handles same-currency transfers where the recipient
 * is not yet a registered UlendoPay user.
 * Funds are held in escrow and a PendingClaim is created.
 * Claim expires after 48 hours.
 */
class PendingClaimHandler implements TransferHandlerInterface
{
    public function __construct(private LedgerService $ledger) {}

    public function supports(TransactionContext $ctx): bool
    {
        if (! $ctx->isSameCurrency) {
            return false;
        }

        if ($ctx->recipient->payment_method === 'wallet_transfer') {
            return false;
        }

        return $ctx->resolvedRecipientUser === null;
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
            reference:   "TXN-{$ctx->reference}-HOLD",
            type:        'transfer_initiation',
            currency:    $ctx->sendCurrency,
            entries: [
                [
                    'account_id'  => $senderAccount->id,
                    'type'        => 'debit',
                    'amount'      => $ctx->sendAmount,
                    'description' => "Transfer hold for unregistered recipient {$ctx->reference}",
                ],
                [
                    'account_id'  => $escrowAccount->id,
                    'type'        => 'credit',
                    'amount'      => $ctx->sendAmount,
                    'description' => "Held pending claim {$ctx->reference}",
                ],
            ],
            description: "Pending claim transfer {$ctx->reference}"
        );

        $transaction->update([
            'journal_entry_group_id' => $group->id,
            'status'                 => 'pending_claim',
            'escrowed_at'            => Carbon::now(),
        ]);

        $ctx->rateLock->update(['status' => 'used', 'used_at' => Carbon::now()]);

        $phoneHash   = hash('sha256', $ctx->recipient->mobile_number);
        $maskedPhone = substr($ctx->recipient->mobile_number, 0, 4)
            . str_repeat('*', max(0, strlen($ctx->recipient->mobile_number) - 7))
            . substr($ctx->recipient->mobile_number, -3);

        PendingClaim::create([
            'transaction_id'         => $transaction->id,
            'recipient_phone_hash'   => $phoneHash,
            'recipient_phone_masked' => $maskedPhone,
            'amount'                 => $ctx->sendAmount,
            'currency_code'          => $ctx->sendCurrency,
            'status'                 => 'pending',
            'expires_at'             => Carbon::now()->addHours(48),
        ]);

        OutboxEvent::create([
            'event_type'     => 'sms_notification',
            'transaction_id' => $transaction->id,
            'payload'        => [
                'type'      => 'transfer_held',
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
                'type'       => 'pending_claim',
                'reference'  => $ctx->reference,
                'amount'     => $ctx->sendAmount,
                'currency'   => $ctx->sendCurrency,
                'phone'      => $ctx->recipient->mobile_number,
                'expires_at' => Carbon::now()->addHours(48)->toDateTimeString(),
            ],
            'status'          => 'pending',
            'next_attempt_at' => Carbon::now(),
        ]);

        return $transaction;
    }
}
