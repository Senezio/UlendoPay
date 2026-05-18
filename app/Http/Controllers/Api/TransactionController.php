<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RateLock;
use App\Models\Recipient;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

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

        $rateLock = RateLock::where('id', $data['rate_lock_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (bccomp((string)$data['send_amount'], (string)$rateLock->send_amount, 2) !== 0) {
            return response()->json([
                'message' => 'Send amount does not match the locked rate. Please get a new quote.',
            ], 422);
        }

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

        // Invalidate cached transaction count for this user
        Cache::forget("txn_count:{$request->user()->id}");

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
        $offset  = ($page - 1) * $perPage;

        // ── Build union query at DB level ────────────────────────────────────
        // Sent: transactions where this user is the sender
        $sent = DB::table('transactions as t')
            ->join('recipients as r', 'r.id', '=', 't.recipient_id')
            ->join('users as s', 's.id', '=', 't.sender_id')
            ->where('t.sender_id', $user->id)
            ->select([
                't.id',
                't.reference_number',
                't.status',
                't.send_amount',
                't.send_currency',
                't.receive_amount',
                't.receive_currency',
                't.locked_rate',
                't.fee_amount',
                's.name as sender_name',
                'r.full_name as recipient_name',
                't.created_at',
                't.completed_at',
                DB::raw("'sent' as direction"),
            ]);

        // Received (mobile money): recipient phone_hash matches this user
        $receivedMobile = DB::table('transactions as t')
            ->join('recipients as r', 'r.id', '=', 't.recipient_id')
            ->join('users as s', 's.id', '=', 't.sender_id')
            ->where('t.sender_id', '!=', $user->id)
            ->where('r.phone_hash', $user->phone_hash)
            ->where('r.payment_method', 'mobile_money')
            ->select([
                't.id',
                't.reference_number',
                't.status',
                't.send_amount',
                't.send_currency',
                't.receive_amount',
                't.receive_currency',
                't.locked_rate',
                't.fee_amount',
                's.name as sender_name',
                'r.full_name as recipient_name',
                't.created_at',
                't.completed_at',
                DB::raw("'received' as direction"),
            ]);

        // Received (bank): recipient bank_account_number matches user's bank account
        // Joins to recipients owned by this user with matching bank account numbers
        $receivedBank = DB::table('transactions as t')
            ->join('recipients as r', 'r.id', '=', 't.recipient_id')
            ->join('users as s', 's.id', '=', 't.sender_id')
            ->join('recipients as ur', function ($join) use ($user) {
                $join->on('ur.bank_account_number', '=', 'r.bank_account_number')
                     ->where('ur.user_id', $user->id)
                     ->where('ur.payment_method', 'bank_transfer');
            })
            ->where('t.sender_id', '!=', $user->id)
            ->where('r.payment_method', 'bank_transfer')
            ->whereNotNull('r.bank_account_number')
            ->select([
                't.id',
                't.reference_number',
                't.status',
                't.send_amount',
                't.send_currency',
                't.receive_amount',
                't.receive_currency',
                't.locked_rate',
                't.fee_amount',
                's.name as sender_name',
                'r.full_name as recipient_name',
                't.created_at',
                't.completed_at',
                DB::raw("'received' as direction"),
            ]);

        // ── Get total count from Redis cache (60 second TTL) ─────────────────
        $cacheKey = "txn_count:{$user->id}";
        $total    = Cache::remember($cacheKey, 60, function () use ($sent, $receivedMobile, $receivedBank) {
            return DB::table(
                $sent->union($receivedMobile)->union($receivedBank)
            )->count();
        });

        // ── Paginate at DB level ─────────────────────────────────────────────
        $items = DB::table(
                $sent->union($receivedMobile)->union($receivedBank)
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id') // tiebreaker for same-second transactions
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return response()->json([
            'data'         => $items->map(fn ($t) => $this->formatRow($t)),
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

        $transaction = Transaction::where('reference_number', $reference)
            ->where(function ($q) use ($user) {
                $q->where('sender_id', $user->id)
                  ->orWhereHas('recipient', function ($q2) use ($user) {
                      $q2->where('phone_hash', $user->phone_hash)
                         ->orWhere(function ($q3) use ($user) {
                             $q3->where('payment_method', 'bank_transfer')
                                ->whereIn('bank_account_number', function ($sub) use ($user) {
                                    $sub->select('bank_account_number')
                                        ->from('recipients')
                                        ->where('user_id', $user->id)
                                        ->where('payment_method', 'bank_transfer')
                                        ->whereNotNull('bank_account_number');
                                });
                         });
                  });
            })
            ->with(['recipient', 'sender', 'disbursements'])
            ->firstOrFail();

        $direction = $transaction->sender_id === $user->id ? 'sent' : 'received';

        return response()->json([
            'transaction' => $this->formatTransaction($transaction, $direction),
        ]);
    }

    // ── Formatters ───────────────────────────────────────────────────────────

    /**
     * Format a raw DB row from the union query (index).
     */
    private function formatRow(object $t): array
    {
        return [
            'reference'        => $t->reference_number,
            'direction'        => $t->direction,
            'status'           => $t->status,
            'send_amount'      => $t->send_amount,
            'send_currency'    => $t->send_currency,
            'receive_amount'   => $t->receive_amount,
            'receive_currency' => $t->receive_currency,
            'locked_rate'      => $t->locked_rate,
            'fee_amount'       => $t->fee_amount,
            'sender_name'      => $t->sender_name,
            'recipient_name'   => $t->recipient_name,
            'created_at'       => $t->created_at,
            'completed_at'     => $t->completed_at,
        ];
    }

    /**
     * Format an Eloquent Transaction model (store/show).
     */
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
