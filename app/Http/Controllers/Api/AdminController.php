<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Transaction;
use App\Models\TopUp;
use App\Models\KycRecord;
use App\Models\ExchangeRate;
use App\Models\FraudAlert;
use App\Models\AuditLog;
use App\Services\KycService;
use App\Models\Partner;
use App\Models\PartnerCorridor;
use App\Services\RateEngine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function __construct(
        private readonly KycService $kycService,
        private readonly RateEngine $rateEngine,
    ) {}

    // ── Dashboard Stats ───────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $stats = [
            'users' => [
                'total'      => User::where('is_staff', false)->count(),
                'active'     => User::where('is_staff', false)->where('status', 'active')->count(),
                'suspended'  => User::where('is_staff', false)->where('status', 'suspended')->count(),
                'today'      => User::where('is_staff', false)->whereDate('created_at', today())->count(),
                'kyc_pending'=> KycRecord::where('status', 'pending')->count(),
            ],
            'transactions' => [
                'total'      => Transaction::count(),
                'today'      => Transaction::whereDate('created_at', today())->count(),
                'completed'  => Transaction::where('status', 'completed')->count(),
                'failed'     => Transaction::where('status', 'failed')->count(),
                'volume_today' => Transaction::whereDate('created_at', today())
                    ->where('status', 'completed')
                    ->sum('send_amount'),
            ],
            'topups' => [
                'total'        => TopUp::count(),
                'today'        => TopUp::whereDate('created_at', today())->count(),
                'completed'    => TopUp::where('status', 'completed')->count(),
                'volume_today' => TopUp::whereDate('created_at', today())
                    ->where('status', 'completed')
                    ->sum('amount'),
            ],
            'rates' => [
                'active'      => ExchangeRate::where('is_active', true)->count(),
                'stale'       => ExchangeRate::where('is_stale', true)->count(),
                'last_fetched'=> ExchangeRate::where('is_active', true)
                    ->latest('fetched_at')
                    ->value('fetched_at'),
            ],
            'fraud_alerts' => [
                'new'        => FraudAlert::where('status', 'new')->count(),
                'reviewing'  => FraudAlert::where('status', 'reviewing')->count(),
            ],
            'compliance_alerts' => [
                'new'        => \App\Models\ComplianceAlert::where('status', 'new')->count(),
                'reviewing'  => \App\Models\ComplianceAlert::where('status', 'reviewing')->count(),
            ],
        ];

        return response()->json($stats);
    }

    // ── KYC Management ────────────────────────────────────────────────────

    public function kycQueue(Request $request): JsonResponse
    {
        $records = KycRecord::with('user:id,name,email,phone_encrypted,phone_hash,country_code,tier,kyc_status,created_at')
            ->where('status', 'pending')
            ->latest()
            ->paginate(20);

        return response()->json($records);
    }

    public function kycShow(Request $request, int $id): JsonResponse
    {
        $record = KycRecord::with('user')->findOrFail($id);

        try {
            $documentUrl = $this->kycService->getSecureUrl($record, $request->user()->id);
        } catch (\Throwable $e) {
            $documentUrl = null;
        }

        return response()->json([
            'record' => array_merge($record->toArray(), [
                'document_url' => $documentUrl,
            ]),
            'user'   => [
                'id'           => $record->user->id,
                'name'         => $record->user->name,
                'email'        => $record->user->email,
                'phone'        => $record->user->phone,
                'country_code' => $record->user->country_code,
                'kyc_status'   => $record->user->kyc_status,
                'tier'         => $record->user->tier,
                'created_at'   => $record->user->created_at,
            ],
        ]);
    }


    public function kycVerified(Request $request): JsonResponse
    {
        $records = KycRecord::with('user:id,name,email,phone_encrypted,phone_hash,country_code,tier,kyc_status,created_at')
            ->whereIn('status', ['approved', 'verified'])
            ->latest('updated_at')
            ->paginate(50);

        return response()->json($records);
    }

    public function kycApprove(Request $request, int $id): JsonResponse
    {
        $record = KycRecord::findOrFail($id);

        try {
            $this->kycService->approve($record, $request->user());

            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'admin.kyc.approved',
                'entity_type' => 'KycRecord',
                'entity_id'   => $record->id,
                'ip_address'  => $request->ip(),
            ]);

            return response()->json([
                'message' => 'KYC approved successfully.',
                'record'  => $record->fresh(),
            ]);

        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function kycReject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $record = KycRecord::findOrFail($id);

        try {
            $this->kycService->reject($record, $request->user(), $data['reason']);

            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'admin.kyc.rejected',
                'entity_type' => 'KycRecord',
                'entity_id'   => $record->id,
                'new_values'  => ['reason' => $data['reason']],
                'ip_address'  => $request->ip(),
            ]);

            return response()->json([
                'message' => 'KYC rejected.',
                'record'  => $record->fresh(),
            ]);

        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── User Management ───────────────────────────────────────────────────

    public function users(Request $request): JsonResponse
    {
        $query = User::where('is_staff', false)
            ->with('wallets');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('kyc_status')) {
            $query->where('kyc_status', $request->kyc_status);
        }

        if ($request->filled('tier')) {
            $query->where('tier', $request->tier);
        }

        $users = $query->latest()->paginate(25);

        return response()->json($users);
    }

    public function userShow(Request $request, int $id): JsonResponse
    {
        $user = User::with([
            'wallets.account.balance',
            'kycRecords',
            'transactions' => fn($q) => $q->latest()->limit(10),
        ])->findOrFail($id);

        return response()->json([
            'user' => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'phone'        => $user->phone,
                'country_code' => $user->country_code,
                'kyc_status'   => $user->kyc_status,
                'status'       => $user->status,
                'created_at'   => $user->created_at,
                'last_login_at'=> $user->last_login_at,
            ],
            'wallets'      => $user->wallets,
            'kyc_records'  => $user->kycRecords,
            'transactions' => $user->transactions,
        ]);
    }

    public function userSuspend(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $user = User::where('is_staff', false)->findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot suspend your own account.',
            ], 422);
        }

        $user->update(['status' => 'suspended']);

        // Revoke all tokens
        $user->tokens()->delete();

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.user.suspended',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'old_values'  => ['status' => 'active'],
            'new_values'  => ['status' => 'suspended', 'reason' => $data['reason']],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'User suspended successfully.']);
    }

    public function userRestore(Request $request, int $id): JsonResponse
    {
        $user = User::where('is_staff', false)->findOrFail($id);
        $user->update(['status' => 'active']);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.user.restored',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'old_values'  => ['status' => 'suspended'],
            'new_values'  => ['status' => 'active'],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'User restored successfully.']);
    }

    // ── Transaction Monitoring ────────────────────────────────────────────

    public function transactions(Request $request): JsonResponse
    {
        $query = Transaction::with([
            'sender:id,name,email',
            'recipient:id,full_name,mobile_number,country_code',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from_currency')) {
            $query->where('send_currency', $request->from_currency);
        }

        if ($request->filled('to_currency')) {
            $query->where('receive_currency', $request->to_currency);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $query->where('reference_number', 'like', "%{$request->search}%");
        }

        $transactions = $query->latest()->paginate(25);

        return response()->json($transactions);
    }

    public function transactionShow(Request $request, string $reference): JsonResponse
    {
        $transaction = Transaction::with([
            'sender:id,name,email',
            'recipient',
            'partner',
            'disbursements',
            'journalGroup.entries.account',
        ])->where('reference_number', $reference)->firstOrFail();

        return response()->json(['transaction' => $transaction]);
    }

    // ── Exchange Rate Management ──────────────────────────────────────────

    public function rates(Request $request): JsonResponse
    {
        $rates = ExchangeRate::where('is_active', true)
            ->orderBy('from_currency')
            ->orderBy('to_currency')
            ->get();

        return response()->json(['rates' => $rates]);
    }

    public function fetchRates(Request $request): JsonResponse
    {
        // Only super_admin can trigger manual rate fetch
        if ($request->user()->role !== 'super_admin') {
            return response()->json([
                'message' => 'Only super admins can trigger rate fetches.',
            ], 403);
        }

        try {
            $results = $this->rateEngine->fetchAndStore();

            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'admin.rates.fetched',
                'entity_type' => 'ExchangeRate',
                'entity_id'   => 'manual',
                'new_values'  => $results,
                'ip_address'  => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Exchange rates updated successfully.',
                'results' => $results,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Rate fetch failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ── Fraud Alerts ──────────────────────────────────────────────────────

    public function fraudAlerts(Request $request): JsonResponse
    {
        $query = FraudAlert::with([
            'user:id,name,email',
            'transaction:id,reference_number,send_amount,send_currency,status',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $alerts = $query->orderByDesc('risk_score')->paginate(25);

        return response()->json($alerts);
    }

    public function fraudAlertClear(Request $request, int $id): JsonResponse
    {
        $alert = FraudAlert::findOrFail($id);
        $alert->update([
            'status'           => 'cleared',
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'resolution_notes' => $request->input('notes'),
        ]);

        return response()->json(['message' => 'Alert cleared.']);
    }

    public function fraudAlertConfirm(Request $request, int $id): JsonResponse
    {
        $alert = FraudAlert::findOrFail($id);
        $alert->update([
            'status'           => 'confirmed',
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'resolution_notes' => $request->input('notes'),
        ]);

        // Auto-suspend the user if fraud confirmed
        if ($alert->user_id) {
            User::find($alert->user_id)?->update(['status' => 'suspended']);
        }

        return response()->json(['message' => 'Fraud confirmed. User suspended.']);
    }

    // ── Tier Management ──────────────────────────────────────────────────────

    public function tierList(): JsonResponse
    {
        $tiers = \App\Models\TransferTier::orderBy('id')->get();
        return response()->json(['tiers' => $tiers]);
    }

    public function tierCreate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                  => 'required|string|unique:transfer_tiers,name',
            'label'                 => 'required|string',
            'daily_limit'           => 'required|numeric|min:0',
            'monthly_limit'         => 'required|numeric|min:0',
            'per_transaction_limit' => 'required|numeric|min:0',
            'fee_discount_percent'  => 'required|numeric|min:0|max:100',
        ]);

        $tier = \App\Models\TransferTier::create($data);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.tier.created',
            'entity_type' => 'TransferTier',
            'entity_id'   => $tier->id,
            'new_values'  => $data,
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Tier created successfully.', 'tier' => $tier], 201);
    }

    public function tierUpdate(Request $request, int $id): JsonResponse
    {
        $tier = \App\Models\TransferTier::findOrFail($id);

        $data = $request->validate([
            'label'                 => 'sometimes|string',
            'daily_limit'           => 'sometimes|numeric|min:0',
            'monthly_limit'         => 'sometimes|numeric|min:0',
            'per_transaction_limit' => 'sometimes|numeric|min:0',
            'fee_discount_percent'  => 'sometimes|numeric|min:0|max:100',
            'is_active'             => 'sometimes|boolean',
        ]);

        $tier->update($data);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.tier.updated',
            'entity_type' => 'TransferTier',
            'entity_id'   => $tier->id,
            'new_values'  => $data,
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Tier updated successfully.', 'tier' => $tier]);
    }

    public function userUpgradeTier(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tier'   => 'required|in:' . \App\Models\TransferTier::where('is_active', true)->pluck('name')->implode(','),
            'reason' => 'nullable|string',
        ]);

        $user = \App\Models\User::findOrFail($id);
        $oldTier = $user->tier;

        // Validate upgrade direction using level from DB — no hardcoded tier names
        $tiers = \App\Models\TransferTier::where('is_active', true)->pluck('level', 'name');
        if (($tiers[$data['tier']] ?? -1) <= ($tiers[$oldTier] ?? -1)) {
            return response()->json(['message' => 'Can only upgrade to a higher tier.'], 422);
        }

        $user->update(['tier' => $data['tier']]);

        // Update kyc_status based on tier level — highest tier = verified, others = pending
        $maxLevel = \App\Models\TransferTier::where('is_active', true)->max('level');
        $newLevel  = $tiers[$data['tier']] ?? -1;
        $user->update(['kyc_status' => $newLevel >= $maxLevel ? 'verified' : 'pending']);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.user.tier_upgraded',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'new_values'  => ['from' => $oldTier, 'to' => $data['tier'], 'reason' => $data['reason'] ?? null],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => "User upgraded to {$data['tier']} tier successfully."]);
    }

    // ── Staff Management (super_admin only) ───────────────────────────────

    public function staffList(): JsonResponse
    {
        $staff = User::where('is_staff', true)
            ->get(['id', 'name', 'email', 'role', 'status', 'last_login_at', 'created_at']);

        return response()->json(['staff' => $staff]);
    }

    public function staffCreate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'role'     => 'required|in:super_admin,kyc_reviewer,finance_officer,support_agent',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
            'is_staff' => true,
            'role'     => $data['role'],
            'status'   => 'active',
            'kyc_status' => 'verified',
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.staff.created',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'new_values'  => ['name' => $user->name, 'role' => $user->role],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Staff account created.',
            'user'    => $user->only(['id', 'name', 'email', 'role']),
        ], 201);
    }

    // ── Analytics ─────────────────────────────────────────────────────────

    public function analytics(Request $request): JsonResponse
    {
        $days = (int) $request->input('days', 30);
        $days = min(max($days, 7), 90);

        $labels = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $labels[] = now()->subDays($i)->format('M j');
        }

        // Get all active send currencies dynamically
        $currencies = Transaction::where('status', 'completed')
            ->distinct()
            ->pluck('send_currency')
            ->sort()
            ->values();

        // If no transactions yet, fall back to account currencies
        if ($currencies->isEmpty()) {
            $currencies = \App\Models\Account::where('type', 'fee')
                ->distinct()
                ->pluck('currency_code')
                ->sort()
                ->values();
        }

        // Build per-currency volume and revenue series
        $volumeByCurrency  = [];
        $revenueByCurrency = [];

        foreach ($currencies as $currency) {
            $volSeries = [];
            $revSeries = [];

            for ($i = $days - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();

                $vol = (float) Transaction::whereDate('created_at', $date)
                    ->where('status', 'completed')
                    ->where('send_currency', $currency)
                    ->sum('send_amount');

                $rev = (float) \App\Models\JournalEntry::whereHas('account', fn($q) =>
                        $q->where('type', 'fee')->where('currency_code', $currency))
                    ->where('entry_type', 'credit')
                    ->whereDate('posted_at', $date)
                    ->sum('amount');

                $volSeries[] = round($vol, 2);
                $revSeries[] = round($rev, 2);
            }

            $volumeByCurrency[$currency]  = $volSeries;
            $revenueByCurrency[$currency] = $revSeries;
        }

        // Daily transaction counts and top-ups (not currency specific)
        $transactions = [];
        $topups       = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();

            $transactions[] = Transaction::whereDate('created_at', $date)->count();
            $topups[]       = \App\Models\TopUp::whereDate('created_at', $date)
                ->where('status', 'completed')
                ->count();
        }

        // Corridor breakdown — dynamic, no hardcoding
        $corridors = Transaction::where('status', 'completed')
            ->selectRaw('send_currency, receive_currency, COUNT(*) as count, SUM(send_amount) as volume')
            ->groupBy('send_currency', 'receive_currency')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        // Account balances — dynamic
        $accounts = \App\Models\Account::whereIn('type', ['fee', 'guarantee', 'escrow'])
            ->with('balance')
            ->get()
            ->map(fn($a) => [
                'code'     => $a->code,
                'type'     => $a->type,
                'currency' => $a->currency_code,
                'balance'  => (float) ($a->balance?->balance ?? 0),
            ]);

        // Summary totals per currency
        $totalsByCurrency = [];
        foreach ($currencies as $currency) {
            $totalsByCurrency[$currency] = [
                'volume'       => array_sum($volumeByCurrency[$currency]),
                'revenue'      => array_sum($revenueByCurrency[$currency]),
                'transactions' => Transaction::where('status', 'completed')
                    ->where('send_currency', $currency)
                    ->whereBetween('created_at', [now()->subDays($days), now()])
                    ->count(),
            ];
        }

        return response()->json([
            'period'             => $days,
            'labels'             => $labels,
            'currencies'         => $currencies->values(),
            'volume_by_currency' => $volumeByCurrency,
            'revenue_by_currency'=> $revenueByCurrency,
            'transactions'       => $transactions,
            'topups'             => $topups,
            'totals_by_currency' => $totalsByCurrency,
            'corridors'          => $corridors,
            'accounts'           => $accounts,
        ]);
    }

    public function accounts(Request $request): \Illuminate\Http\JsonResponse
    {
        $typeFilter = $request->get("type");

        $query = \App\Models\Account::with(["balance", "owner"])
            ->orderBy("type")
            ->orderBy("currency_code");

        if ($typeFilter && $typeFilter !== "all") {
            $query->where("type", $typeFilter);
        }

        $accounts = $query->get()->map(function ($a) {
            $ownerName = null;
            if ($a->owner_type === \App\Models\User::class && $a->owner) {
                $ownerName = $a->owner->name;
            } elseif ($a->owner_type === \App\Models\Partner::class && $a->owner) {
                $ownerName = $a->owner->name;
            }

            return [
                "id"             => $a->id,
                "code"           => $a->code,
                "type"           => $a->type,
                "currency_code"  => $a->currency_code,
                "balance"        => (float) ($a->balance?->balance ?? 0),
                "normal_balance" => $a->normal_balance,
                "corridor"       => $a->corridor,
                "is_active"      => $a->is_active,
                "owner_name"     => $ownerName,
            ];
        });

        // Summary stats
        $summary = [
            "total"    => $accounts->count(),
            "inactive" => $accounts->where("is_active", false)->count(),
            "escrow"   => round($accounts->where("type", "escrow")->sum("balance"), 2),
            "fee"      => round($accounts->where("type", "fee")->sum("balance"), 2),
               "guarantee"=> round($accounts->where("type", "guarantee")->sum("balance"), 2),
            "system"   => round($accounts->where("type", "system")->sum("balance"), 2),
        ];

        return response()->json([
            "accounts" => $accounts,
            "summary"  => $summary,
        ]);
    }

    public function accountToggle(Request $request, int $id): JsonResponse
    {
        $account = \App\Models\Account::findOrFail($id);

        // Prevent toggling user wallets from admin
        if ($account->type === "user_wallet") {
            return response()->json(["message" => "User wallets cannot be toggled from here."], 422);
        }

        $account->update(["is_active" => !$account->is_active]);

        AuditLog::create([
            "user_id"     => $request->user()->id,
            "action"      => $account->is_active ? "account.enabled" : "account.disabled",
            "entity_type" => "Account",
            "entity_id"   => $account->id,
            "new_values"  => ["is_active" => $account->is_active],
        ]);

        return response()->json([
            "message"   => "Account updated.",
            "is_active" => $account->is_active,
        ]);
    }

    public function accountCreate(Request $request): JsonResponse
    {
        $data = $request->validate([
            "code"           => "required|string|unique:accounts,code",
            "type"           => "required|in:escrow,fee,guarantee,system,partner",
            "currency_code"  => "required|string|size:3",
            "normal_balance" => "required|in:debit,credit",
            "corridor"       => "nullable|string",
        ]);

        $account = \App\Models\Account::create($data + ["is_active" => true]);

        \App\Models\AccountBalance::create([
            "account_id"      => $account->id,
            "balance"         => 0,
            "currency_code"   => $account->currency_code,
            "last_updated_at" => now(),
        ]);

        AuditLog::create([
            "user_id"     => $request->user()->id,
            "action"      => "account.created",
            "entity_type" => "Account",
            "entity_id"   => $account->id,
            "new_values"  => $data,
        ]);

        return response()->json(["message" => "Account created.", "account" => $account], 201);
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function settings(): JsonResponse
    {
        $mask = fn($val) => $val ? '••••••' . substr($val, -6) : null;

        // System health checks
        $dbOk = true;
        try { \Illuminate\Support\Facades\DB::connection()->getPdo(); } catch (\Throwable) { $dbOk = false; }

        $cacheOk = true;
        try { \Illuminate\Support\Facades\Cache::put('_health', 1, 5); $cacheOk = \Illuminate\Support\Facades\Cache::get('_health') == 1; } catch (\Throwable) { $cacheOk = false; }

        // Queue stats
        $pendingJobs  = \Illuminate\Support\Facades\DB::table('jobs')->count();
        $failedJobs   = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();

        // Account balances summary
        $accountBalances = \Illuminate\Support\Facades\DB::table('accounts as a')
            ->join('account_balances as b', 'a.id', '=', 'b.account_id')
            ->whereIn('a.type', ['system', 'fee', 'escrow'])
            ->select('a.type', 'a.currency_code', \Illuminate\Support\Facades\DB::raw('SUM(b.balance) as total'))
            ->groupBy('a.type', 'a.currency_code')
            ->orderBy('a.currency_code')
            ->get();

        // Exchange rates last fetched
        $lastRate = \App\Models\ExchangeRate::latest('fetched_at')->first();

        // Active corridors count
        $activeCorridors = \App\Models\PartnerCorridor::where('is_active', true)->count();
        $totalCorridors  = \App\Models\PartnerCorridor::count();

        // Pending transactions
        $pendingTxns = \App\Models\Transaction::whereIn('status', ['pending', 'escrowed', 'processing'])->count();
        $pendingTopups = \App\Models\TopUp::where('status', 'pending')->count();
        $pendingWithdrawals = \App\Models\Withdrawal::where('status', 'pending')->count();

        return response()->json([
            'services' => [
                'pawapay' => [
                    'label'       => 'PawaPay',
                    'environment' => config('services.pawapay.base_url') === 'https://api.pawapay.io' ? 'production' : 'sandbox',
                    'configured'  => !empty(config('services.pawapay.api_token')),
                    'preview'     => $mask(config('services.pawapay.api_token')),
                    'base_url'    => config('services.pawapay.base_url'),
                ],
                'mtn_momo_collection' => [
                    'label'       => 'MTN MoMo (Collection)',
                    'environment' => config('services.mtn_momo.environment'),
                    'configured'  => !empty(config('services.mtn_momo.collection.api_key')),
                    'preview'     => $mask(config('services.mtn_momo.collection.api_key')),
                    'base_url'    => config('services.mtn_momo.base_url'),
                ],
                'mtn_momo_disbursement' => [
                    'label'       => 'MTN MoMo (Disbursement)',
                    'environment' => config('services.mtn_momo.environment'),
                    'configured'  => !empty(config('services.mtn_momo.disbursement.api_key')),
                    'preview'     => $mask(config('services.mtn_momo.disbursement.api_key')),
                    'base_url'    => config('services.mtn_momo.base_url'),
                ],
                'africastalking' => [
                    'label'       => "Africa's Talking (SMS)",
                    'environment' => config('services.africastalking.username') === 'sandbox' ? 'sandbox' : 'production',
                    'configured'  => !empty(config('services.africastalking.api_key')),
                    'preview'     => $mask(config('services.africastalking.api_key')),
                    'base_url'    => 'https://api.africastalking.com',
                ],
            ],
            'app' => [
                'url'         => config('app.url'),
                'environment' => config('app.env'),
                'debug'       => config('app.debug'),
                'timezone'    => config('app.timezone'),
            ],
            'health' => [
                'database' => $dbOk,
                'cache'    => $cacheOk,
            ],
            'queue' => [
                'pending' => $pendingJobs,
                'failed'  => $failedJobs,
            ],
            'transactions' => [
                'pending_transfers'   => $pendingTxns,
                'pending_topups'      => $pendingTopups,
                'pending_withdrawals' => $pendingWithdrawals,
            ],
            'corridors' => [
                'active' => $activeCorridors,
                'total'  => $totalCorridors,
            ],
            'rates' => [
                'last_fetched' => $lastRate?->fetched_at,
                'total'        => \App\Models\ExchangeRate::where('is_active', true)->count(),
            ],
            'balances' => $accountBalances,
        ]);
    }

    

    // ── Webhook Logs ─────────────────────────────────────────────

    public function webhookLogs(Request $request): JsonResponse
    {
        $query = \App\Models\WebhookLog::query()->orderByDesc('received_at');

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }
        if ($request->filled('outcome')) {
            $query->where('outcome', $request->outcome);
        }
        if ($request->filled('from')) {
            $query->whereDate('received_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('received_at', '<=', $request->to);
        }

        $total    = (clone $query)->count();
        $accepted = (clone $query)->where('outcome', 'accepted')->count();
        $failed   = (clone $query)->where('outcome', 'failed')->count();
        $rejected = (clone $query)->where('outcome', 'rejected')->count();

        $paginated = $query->paginate(50);

        return response()->json([
            'logs'      => $paginated->items(),
            'last_page' => $paginated->lastPage(),
            'summary'   => compact('total', 'accepted', 'failed', 'rejected'),
        ]);
    }

    public function webhookLogShow(int $id): JsonResponse
    {
        $log = \App\Models\WebhookLog::findOrFail($id);
        return response()->json(['log' => $log]);
    }


// ── Partner Management ────────────────────────────────────────────────────

    /**
     * List all partners with their corridors and stats.
     */
    public function partners(Request $request): JsonResponse
    {
        $partners = Partner::with('corridors')->get()->map(function ($partner) {
            return [
                'id'                   => $partner->id,
                'name'                 => $partner->name,
                'code'                 => $partner->code,
                'type'                 => $partner->type,
                'country_code'         => $partner->country_code,
                'is_active'            => $partner->is_active,
                'success_rate'         => $partner->success_rate,
                'avg_response_time_ms' => $partner->avg_response_time_ms,
                'timeout_seconds'      => $partner->timeout_seconds,
                'max_retries'          => $partner->max_retries,
                'corridors'            => $partner->corridors->map(fn($c) => [
                    'id'            => $c->id,
                    'from_currency' => $c->from_currency,
                    'to_currency'   => $c->to_currency,
                    'min_amount'    => $c->min_amount,
                    'max_amount'    => $c->max_amount,
                    'fee_percent'   => $c->fee_percent,
                    'fee_flat'      => $c->fee_flat,
                    'priority'      => $c->priority,
                    'is_active'     => $c->is_active,
                ]),
            ];
        });

        return response()->json(['partners' => $partners]);
    }

    /**
     * Toggle partner active status.
     */
    public function partnerToggle(Request $request, int $id): JsonResponse
    {
        $partner = Partner::findOrFail($id);
        $partner->update(['is_active' => !$partner->is_active]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => $partner->is_active ? 'partner.enabled' : 'partner.disabled',
            'entity_type' => 'Partner',
            'entity_id'   => $partner->id,
            'new_values'  => ['is_active' => $partner->is_active],
        ]);

        return response()->json([
            'message'   => 'Partner updated.',
            'is_active' => $partner->is_active,
        ]);
    }

    /**
     * Update corridor settings — fees, limits, active status.
     */
    public function corridorUpdate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'fee_percent' => 'sometimes|numeric|min:0|max:100',
            'fee_flat'    => 'sometimes|numeric|min:0',
            'min_amount'  => 'sometimes|numeric|min:0',
            'max_amount'  => 'sometimes|numeric|min:0',
            'is_active'   => 'sometimes|boolean',
            'priority'    => 'sometimes|integer|min:1',
        ]);

        $corridor = PartnerCorridor::findOrFail($id);
        $corridor->update($data);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'corridor.updated',
            'entity_type' => 'PartnerCorridor',
            'entity_id'   => $corridor->id,
            'new_values'  => $data,
        ]);

        return response()->json([
            'message'  => 'Corridor updated.',
            'corridor' => $corridor->fresh(),
        ]);
    }

    /**
     * Toggle corridor active status.
     */
    public function corridorToggle(Request $request, int $id): JsonResponse
    {
        $corridor = PartnerCorridor::with('partner')->findOrFail($id);
        $corridor->update(['is_active' => !$corridor->is_active]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => $corridor->is_active ? 'corridor.enabled' : 'corridor.disabled',
            'entity_type' => 'PartnerCorridor',
            'entity_id'   => $corridor->id,
            'new_values'  => ['is_active' => $corridor->is_active],
        ]);

        return response()->json([
            'message'   => 'Corridor updated.',
            'is_active' => $corridor->is_active,
        ]);
    }

    public function accountLedger(Request $request, int $id): JsonResponse
    {
        $account = \App\Models\Account::findOrFail($id);

        $entries = \App\Models\JournalEntry::where('account_id', $id)
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

        $totalDebits  = \App\Models\JournalEntry::where('account_id', $id)->where('entry_type', 'debit')->sum('amount');
        $totalCredits = \App\Models\JournalEntry::where('account_id', $id)->where('entry_type', 'credit')->sum('amount');

        return response()->json([
            'account'       => $account,
            'entries'       => $entries,
            'total_debits'  => (float) $totalDebits,
            'total_credits' => (float) $totalCredits,
        ]);
    }

    public function accountAdjust(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'type'   => 'required|in:debit,credit',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|min:5',
        ]);

        $account = \App\Models\Account::findOrFail($id);

        // Find equity contra account for this currency
        $contraAccount = \App\Models\Account::where("type", "system")
            ->where("currency_code", $account->currency_code)
            ->where("code", "like", "%-EQUITY")
            ->first();

        if (!$contraAccount) {
            return response()->json(["message" => "No equity account found for this currency."], 422);
        }
        $contraType = $data['type'] === 'debit' ? 'credit' : 'debit';

        app(\App\Services\LedgerService::class)->post(
            reference:   'ADJ-' . strtoupper(\Illuminate\Support\Str::random(8)),
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
                    'type'        => $contraType,
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


    /**
     * Export transactions as CSV, PDF, or Excel.
     */
    public function exportTransactions(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $data = $request->validate([
            'format' => 'required|in:csv,xlsx,pdf',
            'from'   => 'nullable|date',
            'to'     => 'nullable|date',
            'status' => 'nullable|string',
        ]);

        $query = \App\Models\Transaction::with(['sender:id,name,email', 'recipient:id,full_name,mobile_number'])
            ->latest();

        if (!empty($data['from']))   $query->whereDate('created_at', '>=', $data['from']);
        if (!empty($data['to']))     $query->whereDate('created_at', '<=', $data['to']);
        if (!empty($data['status'])) $query->where('status', $data['status']);

        $transactions = $query->get();

        $rows = $transactions->map(fn($t) => [
            'Reference'       => $t->reference_number,
            'Date'            => $t->created_at->format('Y-m-d H:i'),
            'Sender'          => $t->sender?->name,
            'Recipient'       => $t->recipient?->full_name,
            'Recipient Phone' => $t->recipient?->mobile_number,
            'Send Amount'     => $t->send_amount,
            'Send Currency'   => $t->send_currency,
            'Receive Amount'  => $t->receive_amount,
            'Receive Currency'=> $t->receive_currency,
            'Fee'             => $t->fee_amount,
            'Rate'            => $t->locked_rate,
            'Status'          => $t->status,
        ]);

        $filename = 'transactions_' . now()->format('Ymd_His');

        if ($data['format'] === 'csv') {
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, array_keys($rows->first() ?? []));
            foreach ($rows as $row) {
                fputcsv($handle, array_values($row));
            }
            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);
            return response($csv, 200, [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}.csv\"",
            ]);
        }

        if ($data['format'] === 'xlsx') {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Headers
            $headers = array_keys($rows->first() ?? []);
            foreach ($headers as $col => $header) {
                $sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
                $sheet->getStyleByColumnAndRow($col + 1, 1)->getFont()->setBold(true);
            }

            // Data rows
            foreach ($rows as $rowIndex => $row) {
                foreach (array_values($row) as $col => $value) {
                    $sheet->setCellValueByColumnAndRow($col + 1, $rowIndex + 2, $value);
                }
            }

            // Auto-size columns
            foreach (range(1, count($headers)) as $col) {
                $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            }

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

            $tmpFile = tempnam(sys_get_temp_dir(), 'ulendo_') . '.xlsx';
            $writer->save($tmpFile);

            while (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
            header('Content-Length: ' . filesize($tmpFile));
            header('Cache-Control: no-cache, no-store');
            header('Pragma: no-cache');

            readfile($tmpFile);
            unlink($tmpFile);
            exit;
        }

        // PDF using DomPDF — Clean Minimal Template
        $adminName = $request->user()?->name ?? "Administrator";
        $logoPath  = public_path("logo.png");
        $logoData  = file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : null;
        $logoImg   = $logoData
            ? "<img src=\"data:image/png;base64," . $logoData . "\" style=\"height:48px;width:auto;display:block;\" />"
            : "<div style=\"font-size:22px;font-weight:900;color:#1a1a1a;\">Ulendo<span style=\"color:#e85d04;\">Pay</span></div>";

        $html  = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\">";
        $html .= "<style>";
        $html .= "@page { margin: 20mm; size: A4 landscape; }";
        $html .= "* { box-sizing: border-box; margin: 0; padding: 0; }";
        $html .= "body { font-family: Arial, sans-serif; font-size: 9px; color: #1a1a1a; background: #fff; margin: 0; padding: 0; }";
        $html .= "table { border-collapse: collapse; }";
        $html .= "table.data { width: 100%; border-top: 2px solid #1a1a1a; border-bottom: 1px solid #ccc; margin-top: 6px; }";
        $html .= "table.data thead tr { border-bottom: 1px solid #1a1a1a; }";
        $html .= "table.data th { padding: 7px 8px; text-align: left; font-size: 9px; font-weight: 700; }";
        $html .= "table.data td { padding: 5px 8px; font-size: 9px; color: #333; border-bottom: 1px solid #f0f0f0; }";
        $html .= "table.data tbody tr:nth-child(even) td { background: #f9f9f9; }";
        $html .= "table.data tbody tr:nth-child(odd) td { background: #ffffff; }";
        $html .= "</style></head><body>";

        $html .= "<table width=\"100%\" style=\"margin-bottom:24px;\">";
        $html .= "<tr>";
        $html .= "<td width=\"50%\" style=\"vertical-align:top;\">" . $logoImg;
        $html .= "<div style=\"margin-top:8px;font-size:9px;color:#444;line-height:1.8;\">Ulendo Technologies Limited<br>P.O. Box 37894, Lilongwe 3, Malawi<br>www.ulendopay.com<br>support@ulendopay.com</div>";
        $html .= "</td>";
        $html .= "<td width=\"50%\" style=\"vertical-align:top;text-align:right;\">";
        $html .= "<div style=\"font-size:16px;font-weight:700;color:#1a1a1a;letter-spacing:0.04em;text-transform:uppercase;\">Transaction Export Report</div>";
        $html .= "</td></tr></table>";

        $html .= "<hr style=\"border:none;border-top:1px solid #ccc;margin-bottom:16px;\" />";

        $html .= "<table width=\"100%\" style=\"margin-bottom:16px;\">";
        $html .= "<tr><td style=\"font-size:9px;color:#555;width:120px;padding:3px 0;\">Generated By:</td>";
        $html .= "<td style=\"font-size:9px;color:#1a1a1a;font-weight:600;\">" . htmlspecialchars($adminName) . "</td>";
        $html .= "<td style=\"text-align:right;font-size:9px;color:#555;\">Page 1 of 1</td></tr>";
        $html .= "<tr><td style=\"font-size:9px;color:#555;padding:3px 0;\">Report Date:</td>";
        $html .= "<td style=\"font-size:9px;color:#1a1a1a;\">" . now()->format("d/m/Y H:i") . "</td><td></td></tr>";
        $html .= "<tr><td style=\"font-size:9px;color:#555;padding:3px 0;\">Total Records:</td>";
        $html .= "<td style=\"font-size:9px;color:#1a1a1a;font-weight:600;\">" . $rows->count() . "</td><td></td></tr>";
        $html .= "</table>";

        $html .= "<hr style=\"border:none;border-top:1px solid #ccc;margin-bottom:16px;\" />";

        $html .= "<div style=\"font-size:11px;font-weight:700;color:#1a1a1a;margin-bottom:6px;\">Transactions</div>";

        $html .= "<table class=\"data\"><thead><tr>";
        foreach (array_keys($rows->first() ?? []) as $header) {
            $html .= "<th>" . htmlspecialchars($header) . "</th>";
        }
        $html .= "</tr></thead><tbody>";
        foreach ($rows as $row) {
            $html .= "<tr>";
            foreach ($row as $val) {
                $html .= "<td>" . htmlspecialchars((string)$val) . "</td>";
            }
            $html .= "</tr>";
        }
        $html .= "<tr><td colspan=\"" . count($rows->first() ?? []) . "\" style=\"padding:8px;font-size:9px;color:#999;text-align:center;border-top:1px solid #e0e0e0;\">--- End of Transactions ---</td></tr>";
        $html .= "</tbody></table>";

        $html .= "<div style=\"margin-top:40px;border-top:1px solid #ccc;padding-top:10px;\">";
        $html .= "<table width=\"100%\"><tr>";
        $html .= "<td style=\"font-size:8px;color:#999;\">Ulendo Technologies Limited &middot; P.O. Box 37894, Lilongwe 3, Malawi<br>This is a system-generated document. UlendoPay will NEVER ask for your PIN or password.</td>";
        $html .= "<td style=\"font-size:8px;color:#999;text-align:right;\">&copy; " . now()->year . " Ulendo Technologies Limited. Confidential.</td>";
        $html .= "</tr></table></div>";

        $html .= "</body></html>";

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('a4', 'landscape');

        return $pdf->download($filename . '.pdf');
    }

    /**
     * Retry disbursement for a failed/stuck transaction.
     */
    public function retryTransaction(Request $request, string $reference): \Illuminate\Http\JsonResponse
    {
        $transaction = \App\Models\Transaction::where('reference_number', $reference)
            ->whereIn('status', ['failed', 'escrowed', 'processing', 'retrying'])
            ->firstOrFail();

        // Re-queue via outbox
        \App\Models\OutboxEvent::create([
            'event_type'     => 'disbursement_requested',
            'transaction_id' => $transaction->id,
            'payload'        => [
                'transaction_id' => $transaction->id,
                'manual_retry'   => true,
                'retried_by'     => $request->user()->id,
            ],
            'status'          => 'pending',
            'next_attempt_at' => now(),
        ]);

        $transaction->update(['status' => 'retrying']);

        \App\Models\AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.transaction.retry',
            'entity_type' => 'Transaction',
            'entity_id'   => $transaction->id,
            'new_values'  => ['reference' => $reference, 'manual_retry' => true],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Transaction queued for retry.', 'status' => 'retrying']);
    }

    /**
     * Partner health stats — disbursement attempt breakdown.
     */

    /**
     * Admin audit log — all staff actions across the platform.
     */
    public function adminAuditLog(Request $request): JsonResponse
    {
        $query = \App\Models\AuditLog::with('user')->orderByDesc('created_at');

        if ($request->filled('action'))      $query->where('action', $request->action);
        if ($request->filled('entity_type')) $query->where('entity_type', $request->entity_type);
        if ($request->filled('user_id'))     $query->where('user_id', $request->user_id);
        if ($request->filled('from'))        $query->whereDate('created_at', '>=', $request->from);
        if ($request->filled('to'))          $query->whereDate('created_at', '<=', $request->to);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('action', 'like', "%{$request->search}%")
                  ->orWhere('entity_type', 'like', "%{$request->search}%")
                  ->orWhere('ip_address', 'like', "%{$request->search}%");
            });
        }

        $logs = $query->paginate(50);

        return response()->json([
            'logs' => $logs->map(fn($log) => [
                'id'          => $log->id,
                'action'      => $log->action,
                'entity_type' => $log->entity_type,
                'entity_id'   => $log->entity_id,
                'old_values'  => $log->old_values,
                'new_values'  => $log->new_values,
                'ip_address'  => $log->ip_address,
                'created_at'  => $log->created_at,
                'staff'       => $log->user ? [
                    'id'   => $log->user->id,
                    'name' => $log->user->name,
                    'role' => $log->user->role,
                ] : null,
            ]),
            'total'        => $logs->total(),
            'current_page' => $logs->currentPage(),
            'last_page'    => $logs->lastPage(),
        ]);
    }

    public function partnerHealth(Request $request): \Illuminate\Http\JsonResponse
    {
        $stats = \App\Models\Partner::with(['corridors'])->get()->map(function ($partner) {
            $attempts = \App\Models\DisbursementAttempt::where('partner_id', $partner->id);

            $total    = (clone $attempts)->count();
            $success  = (clone $attempts)->where('status', 'success')->count();
            $failed   = (clone $attempts)->where('status', 'failed')->count();
            $pending  = (clone $attempts)->where('status', 'pending')->count();
            $avgMs    = (clone $attempts)->whereNotNull('response_time_ms')->avg('response_time_ms');

            $recent = (clone $attempts)->with('transaction:id,reference_number,status')
                ->latest('attempted_at')
                ->limit(5)
                ->get()
                ->map(fn($a) => [
                    'reference'       => $a->transaction?->reference_number,
                    'status'          => $a->status,
                    'response_time_ms'=> $a->response_time_ms,
                    'failure_reason'  => $a->failure_reason,
                    'attempted_at'    => $a->attempted_at,
                ]);

            return [
                'id'            => $partner->id,
                'name'          => $partner->name,
                'code'          => $partner->code,
                'is_active'     => $partner->is_active,
                'total'         => $total,
                'success'       => $success,
                'failed'        => $failed,
                'pending'       => $pending,
                'success_rate'  => $total > 0 ? round(($success / $total) * 100, 1) : null,
                'avg_ms'        => $avgMs ? round($avgMs) : null,
                'recent'        => $recent,
            ];
        });

        return response()->json(['partners' => $stats]);
    }


    // ── Compliance Alerts ────────────────────────────────────────────────────

    public function complianceAlerts(Request $request): JsonResponse
    {
        $query = \App\Models\ComplianceAlert::with([
            'user:id,name,email,country_code,kyc_status,status',
            'screen:id,screen_type,input_name,match_score,match_details,triggered_by,screened_at',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('alert_type')) {
            $query->where('alert_type', $request->alert_type);
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        $alerts = $query->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json($alerts);
    }

    public function complianceAlertShow(Request $request, int $id): JsonResponse
    {
        $alert = \App\Models\ComplianceAlert::with([
            'user:id,name,email,country_code,kyc_status,tier,status,created_at',
            'screen',
        ])->findOrFail($id);

        // Resolve matched entry details
        $matchedEntry = null;
        if ($alert->screen?->sanctions_entry_id) {
            $matchedEntry = \App\Models\SanctionsEntry::find(
                $alert->screen->sanctions_entry_id,
                ['id', 'name', 'aliases', 'country_codes', 'date_of_birth', 'source', 'list_reference']
            );
        } elseif ($alert->screen?->pep_entry_id) {
            $matchedEntry = \App\Models\PepEntry::find(
                $alert->screen->pep_entry_id,
                ['id', 'name', 'aliases', 'country_code', 'position', 'risk_level', 'source']
            );
        }

        return response()->json([
            'alert'         => $alert,
            'matched_entry' => $matchedEntry,
        ]);
    }

    public function complianceAlertClear(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:1000']);

        $alert = \App\Models\ComplianceAlert::findOrFail($id);

        if ($alert->status !== 'new' && $alert->status !== 'reviewing') {
            return response()->json(['message' => 'Alert is already resolved.'], 422);
        }

        $alert->update([
            'status'           => 'cleared',
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'resolution_notes' => $request->notes,
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.compliance.cleared',
            'entity_type' => 'ComplianceAlert',
            'entity_id'   => $alert->id,
            'new_values'  => ['notes' => $request->notes],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Alert cleared.']);
    }

    public function complianceAlertConfirm(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:1000']);

        $alert = \App\Models\ComplianceAlert::findOrFail($id);

        if ($alert->status !== 'new' && $alert->status !== 'reviewing') {
            return response()->json(['message' => 'Alert is already resolved.'], 422);
        }

        $alert->update([
            'status'           => 'confirmed',
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'resolution_notes' => $request->notes,
        ]);

        // Escalate — suspend user and freeze wallets if not already done
        $user = \App\Models\User::find($alert->user_id);
        if ($user && $user->status !== 'suspended') {
            $user->update(['status' => 'suspended']);
        }
        \App\Models\Wallet::where('user_id', $alert->user_id)
            ->where('status', 'active')
            ->update(['status' => 'frozen']);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.compliance.confirmed',
            'entity_type' => 'ComplianceAlert',
            'entity_id'   => $alert->id,
            'new_values'  => ['notes' => $request->notes, 'user_suspended' => true],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Match confirmed. User suspended and wallets frozen.']);
    }

    public function complianceAlertReview(Request $request, int $id): JsonResponse
    {
        $alert = \App\Models\ComplianceAlert::findOrFail($id);

        if ($alert->status !== 'new') {
            return response()->json(['message' => 'Alert is not in new status.'], 422);
        }

        $alert->update([
            'status'      => 'reviewing',
            'reviewed_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Alert marked as under review.']);
    }

    public function complianceStats(): JsonResponse
    {
        return response()->json([
            'alerts' => [
                'new'       => \App\Models\ComplianceAlert::where('status', 'new')->count(),
                'reviewing' => \App\Models\ComplianceAlert::where('status', 'reviewing')->count(),
                'confirmed' => \App\Models\ComplianceAlert::where('status', 'confirmed')->count(),
                'cleared'   => \App\Models\ComplianceAlert::where('status', 'cleared')->count(),
            ],
            'by_type' => [
                'sanctions' => \App\Models\ComplianceAlert::where('alert_type', 'sanctions_match')->where('status', 'new')->count(),
                'pep'       => \App\Models\ComplianceAlert::where('alert_type', 'pep_match')->where('status', 'new')->count(),
            ],
            'sanctions_entries' => \App\Models\SanctionsEntry::where('active', true)->count(),
            'pep_entries'       => \App\Models\PepEntry::where('active', true)->count(),
            'last_synced'       => \App\Models\SanctionsEntry::max('last_synced_at'),
        ]);
    }

}
