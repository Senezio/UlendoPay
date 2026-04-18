<?php

namespace App\Services;

use App\Models\Account;
use App\Models\OutboxEvent;
use App\Models\PendingClaim;
use App\Models\RateLock;
use App\Models\Recipient;
use App\Models\Transaction;
use App\Models\User;
use App\Services\IdempotencyService;
use App\Services\LedgerService;
use App\Services\FraudDetectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class TransactionService
{
    public function __construct(
        private LedgerService         $ledger,
        private IdempotencyService    $idempotency,
        private FraudDetectionService $fraud
    ) {}

    /**
     * Initiate a transfer.
     *
     * This is the entry point from the controller.
     * It is fully idempotent — safe to retry with same key.
     *
     * Steps:
     *   1. Acquire idempotency lock
     *   2. Validate inputs
     *   3. Check sender balance
     *   4. Create transaction record
     *   5. Post Group 1 journal entries (escrow)
     *   6. Queue disbursement via outbox
     *   7. Release idempotency lock as completed
     */
    public function initiate(
        string    $idempotencyKey,
        User      $sender,
        Recipient $recipient,
        RateLock  $rateLock,
        float     $sendAmount
    ): Transaction {

        $payload = [
            'sender_id'    => $sender->id,
            'recipient_id' => $recipient->id,
            'rate_lock_id' => $rateLock->id,
            'send_amount'  => $sendAmount,
        ];

        $requestHash = IdempotencyService::hash($idempotencyKey, $payload);

        // ── 1. Acquire idempotency lock ──────────────────────────────────
        $lock = $this->idempotency->acquire(
            key:         $idempotencyKey,
            requestHash: $requestHash,
            userId:      $sender->id,
            endpoint:    'transaction.initiate'
        );

        if ($lock['status'] === 'completed') {
            // Already processed — return the cached transaction
            return Transaction::find($lock['response']['transaction_id']);
        }

        if ($lock['status'] === 'locked') {
            throw new \RuntimeException('This request is already being processed. Please wait.');
        }

        if ($lock['status'] === 'conflict') {
            throw new \RuntimeException('Idempotency key reused with different parameters.');
        }

        $idempotencyRecord = $lock['record'];

        try {
            $transaction = DB::transaction(function () use (
                $sender, $recipient, $rateLock, $sendAmount
            ) {
                // ── 2. Validate inputs ───────────────────────────────────
                if ($rateLock->status !== 'active') {
                    throw new \RuntimeException('Rate lock is no longer active.');
                }
                if ($rateLock->expires_at->isPast()) {
                    throw new \RuntimeException('Rate lock has expired.');
                }
                if ($rateLock->user_id !== $sender->id) {
                    throw new \RuntimeException('Rate lock does not belong to this user.');
                }
                if (! $recipient->is_active || $recipient->user_id !== $sender->id) {
                    throw new \RuntimeException('Invalid recipient.');
                }

                $sendCurrency    = $rateLock->from_currency;
                $receiveCurrency = $rateLock->to_currency;
                $lockedRate      = $rateLock->locked_rate;

                $isSameCurrency = $sendCurrency === $receiveCurrency;

                // Fee calculation
                $feeAmount       = $isSameCurrency ? 0.0 : $this->calculateFee($sendAmount, $rateLock);
                $guaranteeAmount = $isSameCurrency ? 0.0 : $this->calculateGuarantee($sendAmount, $sendCurrency, $receiveCurrency);
                $escrowAmount    = $sendAmount - $feeAmount - $guaranteeAmount;
                $receiveAmount   = round($escrowAmount * $lockedRate, 6);

                if ($escrowAmount <= 0) {
                    throw new \RuntimeException('Send amount is too small to cover fees.');
                }

                // ── 3. Check sender balance (with row lock) ──────────────
                $senderAccount = Account::where('owner_id', $sender->id)
                    ->where('owner_type', User::class)
                    ->where('type', 'user_wallet')
                    ->where('currency_code', $sendCurrency)
                    ->firstOrFail();

                $balance = (float) $this->ledger->getBalance($senderAccount->id);

                if ($balance < $sendAmount) {
                    throw new \RuntimeException(
                        "Insufficient balance. Available: {$balance} {$sendCurrency}, " .
                        "Required: {$sendAmount} {$sendCurrency}"
                    );
                }

                if (!$isSameCurrency) {
                    // System accounts — only needed for cross-currency transfers
                    $escrowAccount    = Account::where('type', 'escrow')
                        ->where('currency_code', $sendCurrency)->firstOrFail();
                    $feeAccount       = Account::where('type', 'fee')
                        ->where('currency_code', $sendCurrency)->firstOrFail();
                    $guaranteeAccount = Account::where('type', 'guarantee')
                        ->where('corridor', "{$sendCurrency}-{$receiveCurrency}")
                        ->where('currency_code', $sendCurrency)->firstOrFail();
                }

                // ── 3b. Fraud detection ─────────────────────────────────
                $fraudAnalysis = $this->fraud->analyse(
                    $sender,
                    $recipient,
                    $sendAmount,
                    $sendCurrency ?? $rateLock->from_currency
                );

                // ── 4. Create transaction record ─────────────────────────
                $reference = $this->generateReference();

                $transaction = Transaction::create([
                    'reference_number'       => $reference,
                    'sender_id'              => $sender->id,
                    'recipient_id'           => $recipient->id,
                    'rate_lock_id'           => $rateLock->id,
                    'send_amount'            => $sendAmount,
                    'send_currency'          => $sendCurrency,
                    'receive_amount'         => $receiveAmount,
                    'receive_currency'       => $receiveCurrency,
                    'locked_rate'            => $lockedRate,
                    'fee_amount'             => $feeAmount,
                    'guarantee_contribution' => $guaranteeAmount,
                    'status'                 => 'initiated',
                    'flagged_for_review'     => $fraudAnalysis['flagged'],
                    'risk_score'             => $fraudAnalysis['score'],
                    'fraud_context'          => $fraudAnalysis['triggered_rules'],
                ]);

                // Create fraud alert if flagged
                if ($fraudAnalysis['flagged']) {
                    $this->fraud->createAlert($transaction, $fraudAnalysis);
                }

                if ($isSameCurrency) {
                    // ── Same-currency: find recipient by phone ────────────
                    $recipientPhoneHash = hash('sha256', $recipient->mobile_number);
                    $recipientUser      = User::where('phone_hash', $recipientPhoneHash)->first();

                    if ($recipientUser) {
                        // Registered user — credit their wallet directly
                        $recipientAccount = Account::where('owner_id', $recipientUser->id)
                            ->where('owner_type', User::class)
                            ->where('type', 'user_wallet')
                            ->where('currency_code', $receiveCurrency)
                            ->first();

                        if (!$recipientAccount) {
                            throw new \RuntimeException(
                                "Recipient does not have a {$receiveCurrency} wallet."
                            );
                        }

                        $group = $this->ledger->post(
                            reference:   "TXN-{$reference}-DIRECT",
                            type:        'transfer_initiation',
                            currency:    $sendCurrency,
                            entries: [
                                [
                                    'account_id'  => $senderAccount->id,
                                    'type'        => 'debit',
                                    'amount'      => $sendAmount,
                                    'description' => "Direct transfer {$reference}",
                                ],
                                [
                                    'account_id'  => $recipientAccount->id,
                                    'type'        => 'credit',
                                    'amount'      => $receiveAmount,
                                    'description' => "Received transfer {$reference}",
                                ],
                            ],
                            description: "Same-currency transfer {$reference}"
                        );

                        $transaction->update([
                            'journal_entry_group_id' => $group->id,
                            'status'                 => 'completed',
                            'escrowed_at'            => Carbon::now(),
                            'completed_at'           => Carbon::now(),
                        ]);

                        $rateLock->update(['status' => 'used', 'used_at' => Carbon::now()]);

                        // SMS to sender
                        OutboxEvent::create([
                            'event_type'     => 'sms_notification',
                            'transaction_id' => $transaction->id,
                            'payload'        => [
                                'type'      => 'transfer_sent',
                                'reference' => $reference,
                                'amount'    => $sendAmount,
                                'currency'  => $sendCurrency,
                                'phone'     => $sender->phone,
                            ],
                            'status'          => 'pending',
                            'next_attempt_at' => Carbon::now(),
                        ]);

                        // SMS to recipient
                        OutboxEvent::create([
                            'event_type'     => 'sms_notification',
                            'transaction_id' => $transaction->id,
                            'payload'        => [
                                'type'      => 'transfer_received',
                                'reference' => $reference,
                                'amount'    => $receiveAmount,
                                'currency'  => $receiveCurrency,
                                'phone'     => $recipientUser->phone,
                            ],
                            'status'          => 'pending',
                            'next_attempt_at' => Carbon::now(),
                        ]);

                    } else {
                        // Unregistered recipient — hold in escrow, create pending claim
                        $escrowAccount = Account::where('type', 'escrow')
                            ->where('currency_code', $sendCurrency)->firstOrFail();

                        $group = $this->ledger->post(
                            reference:   "TXN-{$reference}-HOLD",
                            type:        'transfer_initiation',
                            currency:    $sendCurrency,
                            entries: [
                                [
                                    'account_id'  => $senderAccount->id,
                                    'type'        => 'debit',
                                    'amount'      => $sendAmount,
                                    'description' => "Transfer hold for unregistered recipient {$reference}",
                                ],
                                [
                                    'account_id'  => $escrowAccount->id,
                                    'type'        => 'credit',
                                    'amount'      => $sendAmount,
                                    'description' => "Held pending claim {$reference}",
                                ],
                            ],
                            description: "Pending claim transfer {$reference}"
                        );

                        $transaction->update([
                            'journal_entry_group_id' => $group->id,
                            'status'                 => 'pending_claim',
                            'escrowed_at'            => Carbon::now(),
                        ]);

                        $rateLock->update(['status' => 'used', 'used_at' => Carbon::now()]);

                        // Create pending claim record
                        $maskedPhone = substr($recipient->mobile_number, 0, 4)
                            . str_repeat('*', max(0, strlen($recipient->mobile_number) - 7))
                            . substr($recipient->mobile_number, -3);

                        PendingClaim::create([
                            'transaction_id'        => $transaction->id,
                            'recipient_phone_hash'  => $recipientPhoneHash,
                            'recipient_phone_masked'=> $maskedPhone,
                            'amount'               => $sendAmount,
                            'currency_code'        => $sendCurrency,
                            'status'               => 'pending',
                            'expires_at'           => Carbon::now()->addHours(48),
                        ]);

                        // SMS to sender confirming hold
                        OutboxEvent::create([
                            'event_type'     => 'sms_notification',
                            'transaction_id' => $transaction->id,
                            'payload'        => [
                                'type'      => 'transfer_held',
                                'reference' => $reference,
                                'amount'    => $sendAmount,
                                'currency'  => $sendCurrency,
                                'phone'     => $sender->phone,
                            ],
                            'status'          => 'pending',
                            'next_attempt_at' => Carbon::now(),
                        ]);

                        // SMS to unregistered recipient
                        OutboxEvent::create([
                            'event_type'     => 'sms_notification',
                            'transaction_id' => $transaction->id,
                            'payload'        => [
                                'type'        => 'pending_claim',
                                'reference'   => $reference,
                                'amount'      => $sendAmount,
                                'currency'    => $sendCurrency,
                                'phone'       => $recipient->mobile_number,
                                'expires_at'  => Carbon::now()->addHours(48)->toDateTimeString(),
                            ],
                            'status'          => 'pending',
                            'next_attempt_at' => Carbon::now(),
                        ]);
                    }

                } else {
                    // ── Cross-currency: escrow + disbursement flow ────────
                    $group = $this->ledger->post(
                        reference:   "TXN-{$reference}-INIT",
                        type:        'transfer_initiation',
                        currency:    $sendCurrency,
                        entries: [
                            [
                                'account_id'  => $senderAccount->id,
                                'type'        => 'debit',
                                'amount'      => $sendAmount,
                                'description' => "Transfer initiation {$reference}",
                            ],
                            [
                                'account_id'  => $escrowAccount->id,
                                'type'        => 'credit',
                                'amount'      => $escrowAmount,
                                'description' => "Escrow for {$reference}",
                            ],
                            [
                                'account_id'  => $feeAccount->id,
                                'type'        => 'credit',
                                'amount'      => $feeAmount,
                                'description' => "Fee for {$reference}",
                            ],
                            [
                                'account_id'  => $guaranteeAccount->id,
                                'type'        => 'credit',
                                'amount'      => $guaranteeAmount,
                                'description' => "Guarantee contribution for {$reference}",
                            ],
                        ],
                        description: "Initiation of transfer {$reference}"
                    );

                    $transaction->update([
                        'journal_entry_group_id' => $group->id,
                        'status'                 => 'escrowed',
                        'escrowed_at'            => Carbon::now(),
                    ]);

                    $rateLock->update(['status' => 'used', 'used_at' => Carbon::now()]);

                    // Credit recipient wallet or hold in escrow if unregistered
                    $recipientPhoneHash = hash('sha256', $recipient->mobile_number);
                    $recipientUser      = User::where('phone_hash', $recipientPhoneHash)->first();

                    if ($recipientUser) {
                        $recipientAccount = Account::where('owner_id', $recipientUser->id)
                            ->where('owner_type', User::class)
                            ->where('type', 'user_wallet')
                            ->where('currency_code', $receiveCurrency)
                            ->first();

                        if ($recipientAccount) {
                            // Debit the receive-currency POOL (not escrow) —
                            // the send-side MWK escrow already holds the funds.
                            // ZMW escrow was never funded so debiting it causes negative balance.
                            $receivePoolAccount = Account::where('type', 'system')
                                ->where('code', "{$receiveCurrency}-POOL")
                                ->firstOrFail();

                            $this->ledger->post(
                                reference:   "TXN-{$reference}-CREDIT",
                                type:        'transfer_credit',
                                currency:    $receiveCurrency,
                                entries: [
                                    [
                                        'account_id'  => $receivePoolAccount->id,
                                        'type'        => 'debit',
                                        'amount'      => $receiveAmount,
                                        'description' => "Disbursement release: {$reference}",
                                    ],
                                    [
                                        'account_id'  => $recipientAccount->id,
                                        'type'        => 'credit',
                                        'amount'      => $receiveAmount,
                                        'description' => "Transfer received: {$reference}",
                                    ],
                                ],
                                description: "Wallet credit for cross-currency transfer: {$reference}"
                            );

                            OutboxEvent::create([
                                'event_type'     => 'sms_notification',
                                'transaction_id' => $transaction->id,
                                'payload'        => [
                                    'type'      => 'transfer_received',
                                    'phone'     => $recipientUser->phone,
                                    'amount'    => $receiveAmount,
                                    'currency'  => $receiveCurrency,
                                    'reference' => $reference,
                                ],
                                'status'          => 'pending',
                                'next_attempt_at' => Carbon::now(),
                            ]);

                            $transaction->update([
                                'status'       => 'completed',
                                'completed_at' => now(),
                            ]);
                        } else {
                            $maskedPhone = substr($recipient->mobile_number, 0, 4)
                                . str_repeat('*', max(0, strlen($recipient->mobile_number) - 7))
                                . substr($recipient->mobile_number, -3);

                            PendingClaim::create([
                                'transaction_id'         => $transaction->id,
                                'recipient_phone_hash'   => $recipientPhoneHash,
                                'recipient_phone_masked' => $maskedPhone,
                                'amount'                 => $receiveAmount,
                                'currency_code'          => $receiveCurrency,
                                'status'                 => 'pending',
                                'expires_at'             => Carbon::now()->addHours(48),
                            ]);
                        }
                    } else {
                        $maskedPhone = substr($recipient->mobile_number, 0, 4)
                            . str_repeat('*', max(0, strlen($recipient->mobile_number) - 7))
                            . substr($recipient->mobile_number, -3);

                        PendingClaim::create([
                            'transaction_id'         => $transaction->id,
                            'recipient_phone_hash'   => $recipientPhoneHash,
                            'recipient_phone_masked' => $maskedPhone,
                            'amount'                 => $receiveAmount,
                            'currency_code'          => $receiveCurrency,
                            'status'                 => 'pending',
                            'expires_at'             => Carbon::now()->addHours(48),
                        ]);
                    }

                    OutboxEvent::create([
                        'event_type'     => 'sms_notification',
                        'transaction_id' => $transaction->id,
                        'payload'        => [
                            'type'      => 'transfer_sent',
                            'phone'     => $sender->phone,
                            'amount'    => $sendAmount,
                            'currency'  => $sendCurrency,
                            'reference' => $reference,
                        ],
                        'status'          => 'pending',
                        'next_attempt_at' => Carbon::now(),
                    ]);
                }

                return $transaction;
            });

            // ── Mark idempotency key as completed ────────────────────────
            $this->idempotency->complete($idempotencyRecord, [
                'transaction_id'       => $transaction->id,
                'reference_number'     => $transaction->reference_number,
                'status'               => $transaction->status,
                'receive_amount'       => $transaction->receive_amount,
                'receive_currency'     => $transaction->receive_currency,
            ], 201);

            return $transaction;

        } catch (\Throwable $e) {
            $this->idempotency->release($idempotencyRecord);
            throw $e;
        }
    }

    /**
     * Complete a transaction after partner confirms disbursement.
     * Called by the outbox worker.
     *
     * Posts Group 2: Debit escrow → Credit partner account
     */
    public function complete(Transaction $transaction, string $partnerReference): void
    {
        DB::transaction(function () use ($transaction, $partnerReference) {

            // Re-fetch with lock to prevent concurrent completion
            $transaction = Transaction::where('id', $transaction->id)
                ->whereIn('status', ['escrowed', 'processing', 'retrying'])
                ->lockForUpdate()
                ->firstOrFail();

            $escrowAccount  = Account::where('type', 'escrow')
                ->where('currency_code', $transaction->send_currency)->firstOrFail();
            $partnerAccount = Account::where('type', 'partner')
                ->where('owner_id', $transaction->partner_id)
                ->where('currency_code', $transaction->send_currency)->firstOrFail();

            $escrowAmount = $transaction->send_amount
                - $transaction->fee_amount
                - $transaction->guarantee_contribution;

            $this->ledger->post(
                reference: "TXN-{$transaction->reference_number}-COMPLETE",
                type:      'transfer_completion',
                currency:  $transaction->send_currency,
                entries: [
                    [
                        'account_id'  => $escrowAccount->id,
                        'type'        => 'debit',
                        'amount'      => $escrowAmount,
                        'description' => "Escrow release {$transaction->reference_number}",
                    ],
                    [
                        'account_id'  => $partnerAccount->id,
                        'type'        => 'credit',
                        'amount'      => $escrowAmount,
                        'description' => "Partner settlement {$transaction->reference_number}",
                    ],
                ]
            );

            $transaction->update([
                'status'            => 'completed',
                'partner_reference' => $partnerReference,
                'completed_at'      => Carbon::now(),
            ]);

            // Notify sender via outbox
            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => $transaction->id,
                'payload'        => [
                    'type'      => 'transfer_completed',
                    'reference' => $transaction->reference_number,
                ],
                'status'          => 'pending',
                'next_attempt_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Reverse a transaction after all disbursement attempts fail.
     * Called by the outbox worker when max_retries is exhausted.
     *
     * Posts Group 3: Debit escrow + guarantee → Credit sender
     * Fee is NOT refunded (platform kept it for the attempt).
     */
    public function reverse(Transaction $transaction, string $reason): void
    {
        DB::transaction(function () use ($transaction, $reason) {

            $transaction = Transaction::where('id', $transaction->id)
                ->whereIn('status', ['escrowed', 'processing', 'retrying', 'refund_pending'])
                ->lockForUpdate()
                ->firstOrFail();

            $sendCurrency    = $transaction->send_currency;
            $receiveCurrency = $transaction->receive_currency;

            $senderAccount    = Account::where('owner_id', $transaction->sender_id)
                ->where('owner_type', User::class)
                ->where('type', 'user_wallet')
                ->where('currency_code', $sendCurrency)->firstOrFail();
            $escrowAccount    = Account::where('type', 'escrow')
                ->where('currency_code', $sendCurrency)->firstOrFail();
            $guaranteeAccount = Account::where('type', 'guarantee')
                ->where('corridor', "{$sendCurrency}-{$receiveCurrency}")
                ->where('currency_code', $sendCurrency)->firstOrFail();

            $escrowAmount    = $transaction->send_amount
                - $transaction->fee_amount
                - $transaction->guarantee_contribution;
            $refundAmount    = $escrowAmount + $transaction->guarantee_contribution;

            $this->ledger->post(
                reference:   "TXN-{$transaction->reference_number}-REVERSAL",
                type:        'transfer_reversal',
                currency:    $sendCurrency,
                entries: [
                    [
                        'account_id'  => $escrowAccount->id,
                        'type'        => 'debit',
                        'amount'      => $escrowAmount,
                        'description' => "Reversal escrow release {$transaction->reference_number}",
                    ],
                    [
                        'account_id'  => $guaranteeAccount->id,
                        'type'        => 'debit',
                        'amount'      => $transaction->guarantee_contribution,
                        'description' => "Reversal guarantee return {$transaction->reference_number}",
                    ],
                    [
                        'account_id'  => $senderAccount->id,
                        'type'        => 'credit',
                        'amount'      => $refundAmount,
                        'description' => "Refund for failed transfer {$transaction->reference_number}",
                    ],
                ],
                description: "Reversal: {$reason}"
            );

            $transaction->update([
                'status'         => 'refunded',
                'failure_reason' => $reason,
                'refunded_at'    => Carbon::now(),
            ]);

            OutboxEvent::create([
                'event_type'     => 'sms_notification',
                'transaction_id' => $transaction->id,
                'payload'        => [
                    'type'         => 'transfer_refunded',
                    'reference'    => $transaction->reference_number,
                    'refund_amount' => $refundAmount,
                    'currency'     => $sendCurrency,
                ],
                'status'          => 'pending',
                'next_attempt_at' => Carbon::now(),
            ]);
        });
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private function calculateFee(float $amount, RateLock $rateLock): float
    {
        $percentFee = round($amount * ($rateLock->fee_percent / 100), 6);
        return round($percentFee + $rateLock->fee_flat, 6);
    }

    private function calculateGuarantee(
        float $amount,
        string $fromCurrency,
        string $toCurrency
    ): float {
        // 0.5% guarantee contribution — adjust per corridor business rules
        return round($amount * 0.005, 6);
    }

    private function generateReference(): string
    {
        // Format: ULP-20260408-A3F9K2
        return 'ULP-' . Carbon::now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}
