<?php

namespace App\Services\Outbox;

use App\Models\Account;
use App\Models\OutboxEvent;
use App\Models\PendingClaim;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Outbox\Contracts\OutboxHandlerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendPushNotification;


class InternalSettlementHandler implements OutboxHandlerInterface
{
    public function __construct(private LedgerService $ledger) {}

    public function supports(string $eventType): bool
    {
        return $eventType === 'internal_settlement';
    }

    public function handle(OutboxEvent $event): string
    {
        $transaction = Transaction::findOrFail($event->payload['transaction_id']);

        if (! in_array($transaction->status, ['escrowed', 'processing'])) {
            throw new \RuntimeException(
                "Transaction {$transaction->reference_number} is not in a settleable state. " .
                "Current status: {$transaction->status}"
            );
        }

        $transaction->update([
            'status'          => 'processing',
            'last_attempt_at' => now(),
        ]);

        $sendCurrency    = $transaction->send_currency;
        $receiveCurrency = $transaction->receive_currency;
        $escrowAmount = bcsub(bcsub((string)$transaction->send_amount, (string)$transaction->fee_amount, 6), (string)$transaction->guarantee_contribution, 6);

        $escrowAccount = Account::where('type', 'escrow')
            ->where('currency_code', $sendCurrency)
            ->firstOrFail();

        $sendPool = Account::where('type', 'system')
            ->where('code', "{$sendCurrency}-POOL")
            ->firstOrFail();

        $receivePool = Account::where('type', 'system')
            ->where('code', "{$receiveCurrency}-POOL")
            ->firstOrFail();

        $phoneHash     = hash('sha256', $transaction->recipient->mobile_number);
        $recipientUser = User::where('phone_hash', $phoneHash)->first();

        DB::transaction(function () use (
            $transaction, $escrowAccount, $sendPool, $receivePool,
            $recipientUser, $sendCurrency, $receiveCurrency,
            $escrowAmount, $phoneHash
        ) {
            $reference = $transaction->reference_number;

            $this->ledger->post(
                reference:   "TXN-{$reference}-ESCROW-RELEASE",
                type:        'transfer_escrow_release',
                currency:    $sendCurrency,
                entries: [
                    [
                        'account_id'  => $escrowAccount->id,
                        'type'        => 'debit',
                        'amount'      => $escrowAmount,
                        'description' => "Escrow release: {$reference}",
                    ],
                    [
                        'account_id'  => $sendPool->id,
                        'type'        => 'credit',
                        'amount'      => $escrowAmount,
                        'description' => "Pool funded: {$reference}",
                    ],
                ],
                description: "Escrow release for {$reference}"
            );

            if ($recipientUser) {
                $recipientAccount = Account::where('owner_id', $recipientUser->id)
                    ->where('owner_type', User::class)
                    ->where('type', 'user_wallet')
                    ->where('currency_code', $receiveCurrency)
                    ->first();

                if (! $recipientAccount) {
                    throw new \RuntimeException(
                        "Recipient has no {$receiveCurrency} wallet."
                    );
                }

                $this->ledger->post(
                    reference:   "TXN-{$reference}-CREDIT",
                    type:        'transfer_credit',
                    currency:    $receiveCurrency,
                    entries: [
                        [
                            'account_id'  => $receivePool->id,
                            'type'        => 'debit',
                            'amount'      => bcadd((string)$transaction->receive_amount, '0', 6),
                            'description' => "Pool disbursement: {$reference}",
                        ],
                        [
                            'account_id'  => $recipientAccount->id,
                            'type'        => 'credit',
                            'amount'      => bcadd((string)$transaction->receive_amount, '0', 6),
                            'description' => "Transfer received: {$reference}",
                        ],
                    ],
                    description: "Wallet credit for {$reference}"
                );

                SendPushNotification::dispatch(
                    $recipientUser->id,
                    'Money Received',
                    'You have received ' . $transaction->receive_amount . ' ' . $receiveCurrency . '. Reference: ' . $reference,
                    ['type' => 'transfer_received', 'reference' => $reference]
                );

                OutboxEvent::create([
                    'event_type'     => 'sms_notification',
                    'transaction_id' => $transaction->id,
                    'payload'        => [
                        'type'      => 'transfer_received',
                        'phone'     => $transaction->recipient->mobile_number,
                        'amount'    => $transaction->receive_amount,
                        'currency'  => $receiveCurrency,
                        'reference' => $reference,
                    ],
                    'status'          => 'pending',
                    'next_attempt_at' => now(),
                ]);

            } else {
                $maskedPhone = substr($transaction->recipient->mobile_number, 0, 4)
                    . str_repeat('*', max(0, strlen($transaction->recipient->mobile_number) - 7))
                    . substr($transaction->recipient->mobile_number, -3);

                PendingClaim::create([
                    'transaction_id'         => $transaction->id,
                    'recipient_phone_hash'   => $phoneHash,
                    'recipient_phone_masked' => $maskedPhone,
                    'amount'                 => $transaction->receive_amount,
                    'currency_code'          => $receiveCurrency,
                    'status'                 => 'pending',
                    'expires_at'             => now()->addHours(48),
                ]);

                Log::info("[outbox] Recipient not registered — PendingClaim created", [
                    'reference' => $reference,
                ]);
            }

            $transaction->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            SendPushNotification::dispatch(
                $transaction->sender_id,
                'Transfer Complete',
                'Your transfer ' . $transaction->reference_number . ' has been completed.',
                ['type' => 'transfer_completed', 'reference' => $transaction->reference_number]
            );

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => $transaction->id,
                'payload'        => [
                    'type'      => 'transfer_completed',
                    'reference' => $transaction->reference_number,
                ],
                'status'          => 'pending',
                'next_attempt_at' => now(),
            ]);
        });

        return "Internal settlement complete: {$transaction->reference_number}";
    }
}
