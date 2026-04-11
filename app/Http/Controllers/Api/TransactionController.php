<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RateLock;
use App\Models\Recipient;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function __construct(private TransactionService $transactionService) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => 'required|string|max:255',
            'rate_lock_id'    => 'required|integer',
            'recipient_id'    => 'required|integer',
            'send_amount'     => 'required|numeric|min:1',
        ]);

        $rateLock  = RateLock::where('id', $data['rate_lock_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $recipient = Recipient::where('id', $data['recipient_id'])
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->firstOrFail();

        $transaction = $this->transactionService->initiate(
            idempotencyKey: $data['idempotency_key'],
            sender:         $request->user(),
            recipient:      $recipient,
            rateLock:       $rateLock,
            sendAmount:     (float) $data['send_amount']
        );

        return response()->json([
            'message'     => 'Transfer initiated successfully.',
            'transaction' => $this->formatTransaction($transaction),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $transactions = Transaction::where('sender_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json($transactions);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $transaction = Transaction::where('reference_number', $reference)
            ->where('sender_id', $request->user()->id)
            ->with(['recipient', 'disbursements'])
            ->firstOrFail();

        return response()->json([
            'transaction' => $this->formatTransaction($transaction),
        ]);
    }

    private function formatTransaction(Transaction $t): array
    {
        return [
            'reference'        => $t->reference_number,
            'status'           => $t->status,
            'send_amount'      => $t->send_amount,
            'send_currency'    => $t->send_currency,
            'receive_amount'   => $t->receive_amount,
            'receive_currency' => $t->receive_currency,
            'locked_rate'      => $t->locked_rate,
            'fee_amount'       => $t->fee_amount,
            'created_at'       => $t->created_at,
            'completed_at'     => $t->completed_at,
        ];
    }
}
