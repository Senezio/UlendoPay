<?php

namespace App\Services;

use App\Models\Account;
use App\Models\OutboxEvent;
use App\Models\RateLock;
use App\Models\Recipient;
use App\Models\Transaction;
use App\Models\User;
use App\Services\IdempotencyService;
use App\Services\LedgerService;
use App\Services\TierService;
use App\Services\FraudDetectionService;
use App\Services\Transfers\CrossCurrencyHandler;
use App\Services\Transfers\FeeCalculator;
use App\Services\Transfers\PendingClaimHandler;
use App\Services\Transfers\SameCurrencyDirectHandler;
use App\Services\Transfers\TransactionContext;
use App\Services\Transfers\TransactionValidator;
use App\Services\Transfers\WalletTransferHandler;
use App\Services\Transfers\Contracts\TransferHandlerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class TransactionService
{
    /** @var TransferHandlerInterface[] */
    private array $handlers;

    public function __construct(
        private LedgerService            $ledger,
        private IdempotencyService       $idempotency,
        private FraudDetectionService    $fraud,
        private FeeCalculator            $fees,
        private TransactionValidator     $validator,
        private WalletTransferHandler    $walletHandler,
        private SameCurrencyDirectHandler $directHandler,
        private PendingClaimHandler      $claimHandler,
        private CrossCurrencyHandler     $crossHandler,
    ) {
        // Order matters — wallet and direct/claim are checked before cross-currency
        $this->handlers = [
            $this->walletHandler,
            $this->directHandler,
            $this->claimHandler,
            $this->crossHandler,
        ];
    }

    /**
     * Initiate a transfer.
     *
     * Fully idempotent — safe to retry with the same key.
     *
     * Steps:
     *   1. Acquire idempotency lock
     *   2. Validate inputs
     *   3. Check sender balance
     *   4. Run fraud detection
     *   5. Build TransactionContext
     *   6. Resolve and invoke the correct handler
     *   7. Mark idempotency key as completed
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
                $this->validator->validate($sender, $recipient, $rateLock, $sendAmount);

                $sendCurrency    = $rateLock->from_currency;
                $receiveCurrency = $rateLock->to_currency;
                $lockedRate      = $rateLock->locked_rate;
                $isSameCurrency  = $sendCurrency === $receiveCurrency;

                // ── 3. Check sender balance ──────────────────────────────
                $senderAccount = Account::where('owner_id', $sender->id)
                    ->where('owner_type', User::class)
                    ->where('type', 'user_wallet')
                    ->where('currency_code', $sendCurrency)
                    ->firstOrFail();

                $balance = $this->ledger->getBalance($senderAccount->id);

                if (bccomp((string)$balance, (string)$sendAmount, 6) < 0) {
                    throw new \RuntimeException(
                        "Insufficient balance. Available: {$balance} {$sendCurrency}, " .
                        "Required: {$sendAmount} {$sendCurrency}"
                    );
                }

                // ── 4. Fraud detection ───────────────────────────────────
                $fraudAnalysis = $this->fraud->analyse(
                    $sender, $recipient, $sendAmount, $sendCurrency
                );

                // ── 5. Compute amounts and build context ─────────────────
                $feeAmount       = $isSameCurrency ? 0.0 : $this->fees->effectiveFee($sendAmount, $rateLock, $sender);
                $guaranteeAmount = $isSameCurrency ? 0.0 : $this->fees->guarantee($sendAmount, $sendCurrency, $receiveCurrency, $rateLock->guarantee_percent ?? null);
                $escrowAmount    = $sendAmount - $feeAmount - $guaranteeAmount;
                $receiveAmount   = round($escrowAmount * $lockedRate, 6);

                if ($escrowAmount <= 0) {
                    throw new \RuntimeException('Send amount is too small to cover fees.');
                }

                $reference = 'ULP-' . Carbon::now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                // Pre-fetch once — handlers read from ctx, no repeated queries
                $resolvedRecipientUser = null;
                if ($isSameCurrency && $recipient->payment_method !== 'wallet_transfer') {
                    $phoneHash = hash('sha256', $recipient->mobile_number);
                    $resolvedRecipientUser = \App\Models\User::where('phone_hash', $phoneHash)->first();
                }

                $ctx = new TransactionContext(
                    sender:                $sender,
                    recipient:             $recipient,
                    rateLock:              $rateLock,
                    sendAmount:            $sendAmount,
                    receiveAmount:         $receiveAmount,
                    feeAmount:             $feeAmount,
                    guaranteeAmount:       $guaranteeAmount,
                    escrowAmount:          $escrowAmount,
                    sendCurrency:          $sendCurrency,
                    receiveCurrency:       $receiveCurrency,
                    lockedRate:            $lockedRate,
                    isSameCurrency:        $isSameCurrency,
                    reference:             $reference,
                    resolvedRecipientUser: $resolvedRecipientUser,
                );

                // ── 6. Resolve handler and execute ───────────────────────
                $handler = $this->resolveHandler($ctx);
                $transaction = $handler->handle($ctx);

                // Attach fraud analysis result to transaction
                $transaction->update([
                    'flagged_for_review' => $fraudAnalysis['flagged'],
                    'risk_score'         => $fraudAnalysis['score'],
                    'fraud_context'      => $fraudAnalysis['triggered_rules'],
                ]);

                if ($fraudAnalysis['flagged']) {
                    $this->fraud->createAlert($transaction, $fraudAnalysis);
                }

                return $transaction;
            });

            // ── 7. Mark idempotency key as completed ─────────────────────
            $this->idempotency->complete($idempotencyRecord, [
                'transaction_id'   => $transaction->id,
                'reference_number' => $transaction->reference_number,
                'status'           => $transaction->status,
                'receive_amount'   => $transaction->receive_amount,
                'receive_currency' => $transaction->receive_currency,
            ], 201);

            return $transaction;

        } catch (\Throwable $e) {
            $this->idempotency->release($idempotencyRecord);
            throw $e;
        }
    }

    /**
     * Resolve the correct handler for this transfer context.
     * Throws if no handler claims the context — which means
     * a new transfer type was added without a handler.
     */
    private function resolveHandler(TransactionContext $ctx): TransferHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($ctx)) {
                return $handler;
            }
        }

        throw new \RuntimeException(
            "No handler found for transfer: {$ctx->sendCurrency} -> {$ctx->receiveCurrency}, " .
            "method: {$ctx->recipient->payment_method}"
        );
    }

    /**
     * Complete a transaction after partner confirms disbursement.
     * Called by the outbox worker.
     */
    public function complete(Transaction $transaction, string $partnerReference): void
    {
        DB::transaction(function () use ($transaction, $partnerReference) {

            $transaction = Transaction::where('id', $transaction->id)
                ->whereIn('status', ['escrowed', 'processing', 'retrying'])
                ->lockForUpdate()
                ->firstOrFail();

            $escrowAccount = Account::where('type', 'escrow')
                ->where('currency_code', $transaction->send_currency)->firstOrFail();
            $poolAccount   = Account::where('code', "{$transaction->send_currency}-POOL")
                ->firstOrFail();

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
                        'description' => "Escrow release: {$transaction->reference_number}",
                    ],
                    [
                        'account_id'  => $poolAccount->id,
                        'type'        => 'credit',
                        'amount'      => $escrowAmount,
                        'description' => "Pool funded after disbursement: {$transaction->reference_number}",
                    ],
                ]
            );

            $transaction->update([
                'status'            => 'completed',
                'partner_reference' => $partnerReference,
                'completed_at'      => Carbon::now(),
            ]);

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

}
