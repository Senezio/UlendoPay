<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendPushNotification;


class RefundService
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * Refund a failed transaction back to the sender's wallet.
     *
     * Money flow:
     *   DEBIT  escrow account     <- escrowAmount (send_amount - fee - guarantee)
     *   DEBIT  guarantee account  <- guaranteeAmount
     *   DEBIT  fee account        <- feeAmount
     *   CREDIT sender wallet      <- send_amount (full refund)
     */
    public function refund(Transaction $transaction): void
    {
        $this->assertRefundable($transaction);

        DB::transaction(function () use ($transaction) {

            $transaction->update(['status' => 'refund_pending']);

            $sendCurrency    = $transaction->send_currency;
            $receiveCurrency = $transaction->receive_currency;

            $feeAmount       = $transaction->fee_amount;
            $guaranteeAmount = $transaction->guarantee_contribution;
            $escrowAmount    = bcsub(bcsub((string)$transaction->send_amount, (string)$feeAmount, 6), (string)$guaranteeAmount, 6);
            $refundAmount    = bcadd((string)$transaction->send_amount, '0', 6); // full refund

            $senderAccount = Account::where('owner_id', $transaction->sender_id)
                ->where('owner_type', User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $sendCurrency)
                ->firstOrFail();

            $escrowAccount = Account::where('type', 'escrow')
                ->where('currency_code', $sendCurrency)
                ->firstOrFail();

            $guaranteeAccount = Account::where('type', 'guarantee')
                ->where('corridor', "{$sendCurrency}-{$receiveCurrency}")
                ->where('currency_code', $sendCurrency)
                ->firstOrFail();

            $feeAccount = Account::where('type', 'fee')
                ->where('currency_code', $sendCurrency)
                ->firstOrFail();

            $this->ledger->post(
                reference:   'REFUND-'.$transaction->reference_number,
                type:        'transfer_reversal',
                currency:    $sendCurrency,
                entries: [
                    [
                        'account_id'  => $escrowAccount->id,
                        'type'        => 'debit',
                        'amount'      => bcadd((string)$escrowAmount, '0', 6),
                        'description' => "Refund escrow release: {$transaction->reference_number}",
                    ],
                    [
                        'account_id'  => $guaranteeAccount->id,
                        'type'        => 'debit',
                        'amount'      => bcadd((string)$guaranteeAmount, '0', 6),
                        'description' => "Refund guarantee return: {$transaction->reference_number}",
                    ],
                    [
                        'account_id'  => $feeAccount->id,
                        'type'        => 'debit',
                        'amount'      => bcadd((string)$feeAmount, '0', 6),
                        'description' => "Refund fee return: {$transaction->reference_number}",
                    ],
                    [
                        'account_id'  => $senderAccount->id,
                        'type'        => 'credit',
                        'amount'      => bcadd((string)$refundAmount, '0', 6),
                        'description' => "Full refund received: {$transaction->reference_number}",
                    ],
                ],
                description: "Full refund for failed transfer {$transaction->reference_number}"
            );

            $now = now();

            $transaction->update([
                'status'      => 'refunded',
                'refunded_at' => $now,
            ]);

            AuditLog::create([
                'user_id'     => $transaction->sender_id,
                'action'      => 'transaction.refunded',
                'entity_type' => 'Transaction',
                'entity_id'   => $transaction->id,
                'old_values'  => ['status' => 'failed'],
                'new_values'  => [
                    'status'        => 'refunded',
                    'refunded_at'   => $now,
                    'refund_amount' => $refundAmount,
                    'fee_returned'  => $feeAmount,
                    'currency'      => $sendCurrency,
                ],
            ]);

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => $transaction->id,
                'payload'        => [
                    'transaction_id' => $transaction->id,
                    'type'           => 'transfer_refunded',
                    'reference'      => $transaction->reference_number,
                    'amount'      => bcadd((string)$refundAmount, '0', 6),
                    'currency'       => $sendCurrency,
                ],
                'status' => 'pending',
            ]);

            Log::info('Transaction refunded successfully', [
                'reference'      => $transaction->reference_number,
                'refund_amount'  => $refundAmount,
                'fee_returned'   => $feeAmount,
                'currency'       => $sendCurrency,
                'sender_id'      => $transaction->sender_id,
            ]);
        });

        SendPushNotification::dispatch(
            $transaction->sender_id,
            'Transfer Refunded',
            'Your refund of ' . $transaction->send_amount . ' ' . $transaction->send_currency . ' has been processed.',
            ['type' => 'transfer_refunded', 'reference' => $transaction->reference_number]
        );
    }

    private function assertRefundable(Transaction $transaction): void
    {
        $refundableStates = ['failed', 'refund_pending'];

        if (!in_array($transaction->status, $refundableStates)) {
            throw new \RuntimeException(
                "Transaction {$transaction->reference_number} cannot be refunded. " .
                "Current status: {$transaction->status}. " .
                "Refundable states: " . implode(', ', $refundableStates)
            );
        }

        if (!is_null($transaction->refunded_at)) {
            throw new \RuntimeException(
                "Transaction {$transaction->reference_number} has already been refunded at {$transaction->refunded_at}."
            );
        }
    }
}
