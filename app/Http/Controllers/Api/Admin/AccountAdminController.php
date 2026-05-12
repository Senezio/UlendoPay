<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AccountAdminController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    public function accounts(Request $request): JsonResponse
    {
        $query = Account::with(['balance', 'owner'])->orderBy('type')->orderBy('currency_code');

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        $accounts = $query->get()->map(function ($a) {
            $ownerName = null;
            if ($a->owner_type === User::class && $a->owner) {
                $ownerName = $a->owner->name;
            } elseif ($a->owner_type === \App\Models\Partner::class && $a->owner) {
                $ownerName = $a->owner->name;
            }

            return [
                'id'             => $a->id,
                'code'           => $a->code,
                'type'           => $a->type,
                'currency_code'  => $a->currency_code,
                'balance'        => (float) ($a->balance?->balance ?? 0),
                'normal_balance' => $a->normal_balance,
                'corridor'       => $a->corridor,
                'is_active'      => $a->is_active,
                'owner_name'     => $ownerName,
            ];
        });

        $summary = [
            'total'     => $accounts->count(),
            'inactive'  => $accounts->where('is_active', false)->count(),
            'escrow'    => round($accounts->where('type', 'escrow')->sum('balance'), 2),
            'fee'       => round($accounts->where('type', 'fee')->sum('balance'), 2),
            'guarantee' => round($accounts->where('type', 'guarantee')->sum('balance'), 2),
            'system'    => round($accounts->where('type', 'system')->sum('balance'), 2),
        ];

        return response()->json(['accounts' => $accounts, 'summary' => $summary]);
    }

    public function accountToggle(Request $request, int $id): JsonResponse
    {
        $account = Account::findOrFail($id);

        if ($account->type === 'user_wallet') {
            return response()->json(['message' => 'User wallets cannot be toggled from here.'], 422);
        }

        $account->update(['is_active' => ! $account->is_active]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => $account->is_active ? 'account.enabled' : 'account.disabled',
            'entity_type' => 'Account',
            'entity_id'   => $account->id,
            'new_values'  => ['is_active' => $account->is_active],
        ]);

        return response()->json(['message' => 'Account updated.', 'is_active' => $account->is_active]);
    }

    public function accountCreate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'           => 'required|string|unique:accounts,code',
            'type'           => 'required|in:escrow,fee,guarantee,system,partner',
            'currency_code'  => 'required|string|size:3',
            'normal_balance' => 'required|in:debit,credit',
            'corridor'       => 'nullable|string',
        ]);

        $account = Account::create($data + ['is_active' => true]);

        AccountBalance::create([
            'account_id'      => $account->id,
            'balance'         => 0,
            'currency_code'   => $account->currency_code,
            'last_updated_at' => now(),
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'account.created',
            'entity_type' => 'Account',
            'entity_id'   => $account->id,
            'new_values'  => $data,
        ]);

        return response()->json(['message' => 'Account created.', 'account' => $account], 201);
    }

    public function accountLedger(int $id): JsonResponse
    {
        $account = Account::findOrFail($id);

        $entries = JournalEntry::where('account_id', $id)
            ->with('group')
            ->orderByDesc('posted_at')
            ->limit(100)
            ->get()
            ->map(fn($e) => [
                'id'              => $e->id,
                'entry_type'      => $e->entry_type,
                'amount'          => (float) $e->amount,
                'description'     => $e->description,
                'posted_at'       => $e->posted_at,
                'group_reference' => $e->group?->reference,
            ]);

        return response()->json([
            'account'       => $account,
            'entries'       => $entries,
            'total_debits'  => (float) JournalEntry::where('account_id', $id)->where('entry_type', 'debit')->sum('amount'),
            'total_credits' => (float) JournalEntry::where('account_id', $id)->where('entry_type', 'credit')->sum('amount'),
        ]);
    }

    public function accountAdjust(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'type'   => 'required|in:debit,credit',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|min:5',
        ]);

        $account       = Account::findOrFail($id);
        $contraAccount = Account::where('type', 'system')
            ->where('currency_code', $account->currency_code)
            ->where('code', 'like', '%-EQUITY')
            ->first();

        if (! $contraAccount) {
            return response()->json(['message' => 'No equity account found for this currency.'], 422);
        }

        $this->ledger->post(
            reference:   'ADJ-' . strtoupper(Str::random(8)),
            type:        'adjustment',
            currency:    $account->currency_code,
            entries: [
                [
                    'account_id'  => $account->id,
                    'type'        => $data['type'],
                    'amount'      => $data['amount'],
                    'description' => "Manual adjustment: {$data['reason']}",
                ],
                [
                    'account_id'  => $contraAccount->id,
                    'type'        => $data['type'] === 'debit' ? 'credit' : 'debit',
                    'amount'      => $data['amount'],
                    'description' => "Contra for manual adjustment: {$data['reason']}",
                ],
            ],
            description: "Manual adjustment by admin: {$data['reason']}"
        );

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'account.adjusted',
            'entity_type' => 'Account',
            'entity_id'   => $account->id,
            'new_values'  => $data,
        ]);

        return response()->json(['message' => 'Adjustment posted successfully.']);
    }
}
