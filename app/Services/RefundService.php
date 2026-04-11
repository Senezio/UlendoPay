<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\JournalEntryGroup;
use App\Models\JournalEntry;
use App\Models\OutboxEvent;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RefundService
{
    /**
     * Refund a failed transaction back to the sender's wallet.
     * Atomic — either fully completes or fully rolls back.
     *
     * @throws \RuntimeException if transaction is not refundable
     * @throws \Throwable if database transaction fails
     */
    public function refund(Transaction $transaction): void
    {
        $this->assertRefundable($transaction);

        DB::transaction(function () use ($transaction) {

            // Mark as refund in progress immediately
            // Prevents concurrent refund attempts on same transaction
            $transaction->update(['status' => 'refund_pending']);

            // Resolve accounts
            $escrowAccount  = Account::where('code', "ESCROW-{$transaction->send_currency}")
                ->lockForUpdate()
                ->firstOrFail();

            $senderAccount  = Account::where('code', "USR-{$transaction->sender_id}-{$transaction->send_currency}")
                ->lockForUpdate()
                ->firstOrFail();

            // Verify escrow has sufficient balance for refund
            $escrowBalance  = AccountBalance::where('account_id', $escrowAccount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($escrowBalance->balance < $transaction->send_amount) {
                throw new \RuntimeException(
                    "Escrow balance insufficient for refund on {$transaction->reference_number}. " .
                    "Escrow: {$escrowBalance->balance}, Required: {$transaction->send_amount}"
                );
            }

            $now = now();

            // Create journal entry group for this reversal
            $group = JournalEntryGroup::create([
                'uuid'          => Str::uuid(),
                'currency_code' => $transaction->send_currency,
                'total_amount'  => $transaction->send_amount,
                'type'          => 'transfer_reversal',
                'reference'     => 'REFUND-' . $transaction->reference_number,
                'status'        => 'pending',
                'description'   => "Refund for failed transfer {$transaction->reference_number}",
                'is_balanced'   => false,
            ]);

            // Entry 1: Debit escrow (money leaves escrow)
            JournalEntry::create([
                'group_id'      => $group->id,
                'account_id'    => $escrowAccount->id,
                'entry_type'    => 'debit',
                'amount'        => $transaction->send_amount,
                'currency_code' => $transaction->send_currency,
                'description'   => "Refund release from escrow: {$transaction->reference_number}",
                'posted_at'     => $now,
            ]);

            // Entry 2: Credit sender wallet (money returns to sender)
            JournalEntry::create([
                'group_id'      => $group->id,
                'account_id'    => $senderAccount->id,
                'entry_type'    => 'credit',
                'amount'        => $transaction->send_amount,
                'currency_code' => $transaction->send_currency,
                'description'   => "Refund received: {$transaction->reference_number}",
                'posted_at'     => $now,
            ]);

            // Verify journal group is balanced before posting
            $totalDebits  = $group->entries()->where('entry_type', 'debit')->sum('amount');
            $totalCredits = $group->entries()->where('entry_type', 'credit')->sum('amount');

            if (abs($totalDebits - $totalCredits) > 0.000001) {
                throw new \RuntimeException(
                    "Journal entries not balanced for refund {$transaction->reference_number}. " .
                    "Debits: {$totalDebits}, Credits: {$totalCredits}"
                );
            }

            // Mark group as balanced and posted
            $group->update([
                'is_balanced' => true,
                'status'      => 'posted',
                'posted_at'   => $now,
            ]);

            // Update account balances atomically
            // Escrow decreases
            $escrowBalance->decrement('balance', $transaction->send_amount);
            $escrowBalance->update(['last_updated_at' => $now]);

            // Sender wallet increases
            $senderBalance = AccountBalance::where('account_id', $senderAccount->id)
                ->lockForUpdate()
                ->firstOrFail();

            $senderBalance->increment('balance', $transaction->send_amount);
            $senderBalance->update(['last_updated_at' => $now]);

            // Mark transaction as refunded
            $transaction->update([
                'status'      => 'refunded',
                'refunded_at' => $now,
            ]);

            // Audit log — immutable record of this refund
            AuditLog::create([
                'user_id'     => $transaction->sender_id,
                'action'      => 'transaction.refunded',
                'entity_type' => 'Transaction',
                'entity_id'   => $transaction->id,
                'old_values'  => ['status' => 'failed'],
                'new_values'  => [
                    'status'      => 'refunded',
                    'refunded_at' => $now,
                    'amount'      => $transaction->send_amount,
                    'currency'    => $transaction->send_currency,
                ],
            ]);

            // Queue SMS notification via outbox
            // Keeps this DB transaction short — SMS sending happens async
            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => $transaction->id,
                'payload'        => [
                    'transaction_id' => $transaction->id,
                    'type'           => 'transfer_refunded',
                    'reference'      => $transaction->reference_number,
                    'amount'         => $transaction->send_amount,
                    'currency'       => $transaction->send_currency,
                ],
                'status' => 'pending',
            ]);

            Log::info('Transaction refunded successfully', [
                'reference'   => $transaction->reference_number,
                'amount'      => $transaction->send_amount,
                'currency'    => $transaction->send_currency,
                'sender_id'   => $transaction->sender_id,
                'refunded_at' => $now,
            ]);
        });
    }

    /**
     * Assert the transaction is in a state that allows refunding.
     * Called before opening the DB transaction to fail fast.
     */
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
