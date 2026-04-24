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
            'transaction' => $this->formatTransaction($transaction, 'sent'),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $perPage = 20;
        $page    = (int) $request->get('page', 1);

        // Outgoing: transactions where this user is the sender
        $sent = Transaction::where('sender_id', $user->id)
            ->with(['recipient', 'sender'])
            ->latest()
            ->get()
            ->map(fn($t) => $this->formatTransaction($t, 'sent'));

        // Incoming: transactions where a recipient record matches this user's phone_hash
        $received = Transaction::whereHas('recipient', function ($q) use ($user) {
                $q->where('phone_hash', $user->phone_hash);
            })
            ->with(['recipient', 'sender'])
            ->latest()
            ->get()
            ->map(fn($t) => $this->formatTransaction($t, 'received'));

        // Merge and sort by created_at descending, then paginate manually
        $all = $sent->merge($received)
            ->sortByDesc('created_at')
            ->values();

        $total  = $all->count();
        $offset = ($page - 1) * $perPage;
        $items  = $all->slice($offset, $perPage)->values();

        return response()->json([
            'data'         => $items,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / $perPage),
            'per_page'     => $perPage,
            'total'        => $total,
            'from'         => $total > 0 ? $offset + 1 : null,
            'to'           => $total > 0 ? min($offset + $perPage, $total) : null,
        ]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        // Allow both the sender and the recipient (matched via phone_hash) to view
        $transaction = Transaction::where('reference_number', $reference)
            ->where(function ($q) use ($user) {
                $q->where('sender_id', $user->id)
                  ->orWhereHas('recipient', function ($q2) use ($user) {
                      $q2->where('phone_hash', $user->phone_hash);
                  });
            })
            ->with(['recipient', 'sender', 'disbursements'])
            ->firstOrFail();

        $direction = $transaction->sender_id === $user->id ? 'sent' : 'received';

        return response()->json([
            'transaction' => $this->formatTransaction($transaction, $direction),
        ]);
    }

    private function formatTransaction(Transaction $t, string $direction = 'sent'): array
    {
        return [
            'reference'        => $t->reference_number,
            'direction'        => $direction,
            'status'           => $t->status,
            'send_amount'      => $t->send_amount,
            'send_currency'    => $t->send_currency,
            'receive_amount'   => $t->receive_amount,
            'receive_currency' => $t->receive_currency,
            'locked_rate'      => $t->locked_rate,
            'fee_amount'       => $t->fee_amount,
            'sender_name'      => $t->sender?->name,
            'recipient_name'   => $t->recipient?->full_name,
            'created_at'       => $t->created_at,
            'completed_at'     => $t->completed_at,
        ];
    }
}
