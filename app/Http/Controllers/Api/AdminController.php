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
            $documentUrl = $this->kycService->getSecureUrl($record);
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
                tier         => ->user->tier,
                'created_at'   => $record->user->created_at,
            ],
        ]);
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
            'tier'   => 'required|in:unverified,basic,verified',
            'reason' => 'nullable|string',
        ]);

        $user = \App\Models\User::findOrFail($id);
        $oldTier = $user->tier;

        // Validate upgrade direction
        $tierOrder = ['unverified' => 1, 'basic' => 2, 'verified' => 3];
        if (($tierOrder[$data['tier']] ?? 0) <= ($tierOrder[$oldTier] ?? 0)) {
            return response()->json(['message' => 'Can only upgrade to a higher tier.'], 422);
        }

        $user->update(['tier' => $data['tier']]);

        // If upgrading to verified, also update kyc_status
        if ($data['tier'] === 'verified') {
            $user->update(['kyc_status' => 'verified']);
        } elseif ($data['tier'] === 'basic') {
            $user->update(['kyc_status' => 'pending']);
        }

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
        $days = min(max($days, 7), 90); // clamp between 7 and 90 days

        $labels       = [];
        $transactions = [];
        $volume       = [];
        $revenue      = [];
        $topups       = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $labels[] = now()->subDays($i)->format('M j');

            $txCount = Transaction::whereDate('created_at', $date)->count();
            $txVol   = (float) Transaction::whereDate('created_at', $date)
                ->where('status', 'completed')
                ->sum('send_amount');
            $rev     = (float) \App\Models\JournalEntry::whereHas('account', fn($q) => $q->where('type', 'fee'))
                ->where('entry_type', 'credit')
                ->whereDate('posted_at', $date)
                ->sum('amount');
            $tpCount = \App\Models\TopUp::whereDate('created_at', $date)
                ->where('status', 'completed')
                ->count();

            $transactions[] = $txCount;
            $volume[]       = round($txVol, 2);
            $revenue[]      = round($rev, 2);
            $topups[]       = $tpCount;
        }

        // Corridor breakdown
        $corridors = Transaction::where('status', 'completed')
            ->selectRaw('send_currency, receive_currency, COUNT(*) as count, SUM(send_amount) as volume')
            ->groupBy('send_currency', 'receive_currency')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        // Account balances
        $accounts = \App\Models\Account::whereIn('type', ['fee', 'guarantee', 'escrow'])
            ->with('balance')
            ->get()
            ->map(fn($a) => [
                'code'     => $a->code,
                'type'     => $a->type,
                'currency' => $a->currency_code,
                'balance'  => (float) ($a->balance?->balance ?? 0),
            ]);

        return response()->json([
            'period'       => $days,
            'labels'       => $labels,
            'transactions' => $transactions,
            'volume'       => $volume,
            'revenue'      => $revenue,
            'topups'       => $topups,
            'corridors'    => $corridors,
            'accounts'     => $accounts,
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
        ]);
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
            'Sender Email'    => $t->sender?->email,
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
            $binary = file_get_contents($tmpFile);
            unlink($tmpFile);

            return response($binary, 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$filename}.xlsx\"",
                'Content-Length'      => strlen($binary),
                'Cache-Control'       => 'no-cache, no-store',
            ]);
        }

        // PDF using DomPDF — Professional Template
        $adminName   = $request->user()?->name ?? 'Administrator';
        $logoPath    = public_path('logo.png');
        $logoData    = file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : null;
        $logoImg     = $logoData ? '<img src="data:image/png;base64,' . $logoData . '" style="height:48px;width:auto;" />' : '<strong style="font-size:18px;color:#e85d04;">UlendoPay</strong>';

        $totalRows = $rows->count();

        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        $html .= '<style>';
        $html .= '* { box-sizing: border-box; margin: 0; padding: 0; }';
        $html .= 'body { font-family: Arial, sans-serif; font-size: 8.5px; color: #1a1a1a; background: #fff; }';
        $html .= '.header { display: table; width: 100%; border-bottom: 3px solid #e85d04; padding-bottom: 12px; margin-bottom: 12px; }';
        $html .= '.header-left { display: table-cell; vertical-align: middle; width: 50%; }';
        $html .= '.header-right { display: table-cell; vertical-align: middle; width: 50%; text-align: right; font-size: 7.5px; color: #555; line-height: 1.6; }';
        $html .= '.report-title { font-size: 15px; font-weight: bold; color: #1a1a1a; margin-top: 6px; }';
        $html .= '.report-subtitle { font-size: 8px; color: #888; margin-top: 2px; }';

        $html .= 'table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }';
        $html .= 'table.data thead tr { background: #e85d04; color: #fff; }';
        $html .= 'table.data th { padding: 5px 6px; text-align: left; font-size: 7.5px; font-weight: bold; letter-spacing: 0.03em; border: none; }';
        $html .= 'table.data td { padding: 4px 6px; font-size: 7.5px; border-bottom: 1px solid #f0f0f0; color: #333; }';
        $html .= 'table.data tbody tr:nth-child(even) td { background: #fff7f0; }';
        $html .= 'table.data tbody tr:nth-child(odd) td { background: #ffffff; }';
        $html .= '.status-completed { color: #16a34a; font-weight: bold; }';
        $html .= '.status-failed { color: #dc2626; font-weight: bold; }';
        $html .= '.status-processing, .status-retrying, .status-escrowed { color: #d97706; font-weight: bold; }';
        $html .= '.status-refunded { color: #6b7280; font-weight: bold; }';
        $html .= '.footer { margin-top: 14px; border-top: 1px solid #e5e5e5; padding-top: 8px; display: table; width: 100%; }';
        $html .= '.footer-left { display: table-cell; font-size: 7px; color: #aaa; }';
        $html .= '.footer-right { display: table-cell; text-align: right; font-size: 7px; color: #aaa; }';
        $html .= '.summary { display: table; width: 100%; margin-bottom: 10px; }';
        $html .= '.summary-box { display: table-cell; width: 25%; text-align: center; padding: 6px; border: 1px solid #f0f0f0; border-radius: 4px; }';
        $html .= '.summary-box .num { font-size: 14px; font-weight: bold; color: #e85d04; }';
        $html .= '.summary-box .lbl { font-size: 7px; color: #888; margin-top: 2px; }';
        $html .= '</style></head><body>';

        // Header
        $html .= '<div class="header">';
        $html .= '<div class="header-left">' . $logoImg . '<div class="report-title">Transaction Export Report</div><div class="report-subtitle">Ulendo Technologies Limited</div></div>';
        $html .= '<div class="header-right">';
        $html .= 'Ulendo Technologies Limited<br>';
        $html .= 'P.O. Box 3245, Lilongwe 3, Malawi<br>';
        $html .= 'support@ulendopay.com<br>';
        $html .= 'www.ulendopay.com';
        $html .= '</div></div>';

        // Generated by line under header
        $html .= '<div style="font-size:7.5px;color:#888;margin-bottom:10px;">Generated by <strong>' . htmlspecialchars($adminName) . '</strong> &nbsp;|&nbsp; ' . now()->format('d M Y, H:i') . '</div>';

        // Summary boxes
        $completed = $rows->where('Status', 'completed')->count();
        $failed    = $rows->where('Status', 'failed')->count();
        $html .= '<div class="summary">';
        $html .= '<div class="summary-box"><div class="num">' . $totalRows . '</div><div class="lbl">Total Transactions</div></div>';
        $html .= '<div class="summary-box"><div class="num" style="color:#16a34a;">' . $completed . '</div><div class="lbl">Completed</div></div>';
        $html .= '<div class="summary-box"><div class="num" style="color:#dc2626;">' . $failed . '</div><div class="lbl">Failed</div></div>';
        $html .= '<div class="summary-box"><div class="num" style="color:#6b7280;">' . ($totalRows - $completed - $failed) . '</div><div class="lbl">Other</div></div>';
        $html .= '</div>';

        // Table
        $html .= '<table class="data"><thead><tr>';
        foreach (array_keys($rows->first() ?? []) as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $key => $val) {
                $class = $key === 'Status' ? 'status-' . strtolower((string)$val) : '';
                $html .= '<td class="' . $class . '">' . htmlspecialchars((string)$val) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // Footer
        $html .= '<div class="footer">';
        $html .= '<div class="footer-left">CONFIDENTIAL — For internal use only. Generated by UlendoPay Admin System.</div>';
        $html .= '<div class="footer-right">© ' . now()->year . ' Ulendo Technologies Limited. All rights reserved.</div>';
        $html .= '</div>';

        $html .= '</body></html>';

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

}