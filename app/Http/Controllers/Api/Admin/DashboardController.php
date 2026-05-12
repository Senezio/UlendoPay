<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ComplianceAlert;
use App\Models\ExchangeRate;
use App\Models\FraudAlert;
use App\Models\KycRecord;
use App\Models\TopUp;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        return response()->json([
            'users' => [
                'total'       => User::where('is_staff', false)->count(),
                'active'      => User::where('is_staff', false)->where('status', 'active')->count(),
                'suspended'   => User::where('is_staff', false)->where('status', 'suspended')->count(),
                'today'       => User::where('is_staff', false)->whereDate('created_at', today())->count(),
                'kyc_pending' => KycRecord::where('status', 'pending')->count(),
            ],
            'transactions' => [
                'total'        => Transaction::count(),
                'today'        => Transaction::whereDate('created_at', today())->count(),
                'completed'    => Transaction::where('status', 'completed')->count(),
                'failed'       => Transaction::where('status', 'failed')->count(),
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
                'active'       => ExchangeRate::where('is_active', true)->count(),
                'stale'        => ExchangeRate::where('is_stale', true)->count(),
                'last_fetched' => ExchangeRate::where('is_active', true)->latest('fetched_at')->value('fetched_at'),
            ],
            'fraud_alerts' => [
                'new'       => FraudAlert::where('status', 'new')->count(),
                'reviewing' => FraudAlert::where('status', 'reviewing')->count(),
            ],
            'compliance_alerts' => [
                'new'       => ComplianceAlert::where('status', 'new')->count(),
                'reviewing' => ComplianceAlert::where('status', 'reviewing')->count(),
            ],
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $days = min(max((int) $request->input('days', 30), 7), 90);

        $labels = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $labels[] = now()->subDays($i)->format('M j');
        }

        $currencies = Transaction::where('status', 'completed')
            ->distinct()->pluck('send_currency')->sort()->values();

        if ($currencies->isEmpty()) {
            $currencies = \App\Models\Account::where('type', 'fee')
                ->distinct()->pluck('currency_code')->sort()->values();
        }

        $volumeByCurrency  = [];
        $revenueByCurrency = [];

        foreach ($currencies as $currency) {
            $volSeries = [];
            $revSeries = [];

            for ($i = $days - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();

                $volSeries[] = round((float) Transaction::whereDate('created_at', $date)
                    ->where('status', 'completed')->where('send_currency', $currency)->sum('send_amount'), 2);

                $revSeries[] = round((float) \App\Models\JournalEntry::whereHas('account',
                    fn($q) => $q->where('type', 'fee')->where('currency_code', $currency))
                    ->where('entry_type', 'credit')->whereDate('posted_at', $date)->sum('amount'), 2);
            }

            $volumeByCurrency[$currency]  = $volSeries;
            $revenueByCurrency[$currency] = $revSeries;
        }

        $transactions = [];
        $topups       = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date           = now()->subDays($i)->toDateString();
            $transactions[] = Transaction::whereDate('created_at', $date)->count();
            $topups[]       = \App\Models\TopUp::whereDate('created_at', $date)->where('status', 'completed')->count();
        }

        $corridors = Transaction::where('status', 'completed')
            ->selectRaw('send_currency, receive_currency, COUNT(*) as count, SUM(send_amount) as volume')
            ->groupBy('send_currency', 'receive_currency')
            ->orderByDesc('count')->limit(10)->get();

        $accounts = \App\Models\Account::whereIn('type', ['fee', 'guarantee', 'escrow'])
            ->with('balance')->get()->map(fn($a) => [
                'code'     => $a->code,
                'type'     => $a->type,
                'currency' => $a->currency_code,
                'balance'  => (float) ($a->balance?->balance ?? 0),
            ]);

        $totalsByCurrency = [];
        foreach ($currencies as $currency) {
            $totalsByCurrency[$currency] = [
                'volume'       => array_sum($volumeByCurrency[$currency]),
                'revenue'      => array_sum($revenueByCurrency[$currency]),
                'transactions' => Transaction::where('status', 'completed')
                    ->where('send_currency', $currency)
                    ->whereBetween('created_at', [now()->subDays($days), now()])->count(),
            ];
        }

        return response()->json([
            'period'              => $days,
            'labels'              => $labels,
            'currencies'          => $currencies->values(),
            'volume_by_currency'  => $volumeByCurrency,
            'revenue_by_currency' => $revenueByCurrency,
            'transactions'        => $transactions,
            'topups'              => $topups,
            'totals_by_currency'  => $totalsByCurrency,
            'corridors'           => $corridors,
            'accounts'            => $accounts,
        ]);
    }

    public function settings(): JsonResponse
    {
        $mask = fn($val) => $val ? '••••••' . substr($val, -6) : null;

        $dbOk = true;
        try { \Illuminate\Support\Facades\DB::connection()->getPdo(); } catch (\Throwable) { $dbOk = false; }

        $cacheOk = true;
        try {
            \Illuminate\Support\Facades\Cache::put('_health', 1, 5);
            $cacheOk = \Illuminate\Support\Facades\Cache::get('_health') == 1;
        } catch (\Throwable) { $cacheOk = false; }

        $accountBalances = \Illuminate\Support\Facades\DB::table('accounts as a')
            ->join('account_balances as b', 'a.id', '=', 'b.account_id')
            ->whereIn('a.type', ['system', 'fee', 'escrow'])
            ->select('a.type', 'a.currency_code', \Illuminate\Support\Facades\DB::raw('SUM(b.balance) as total'))
            ->groupBy('a.type', 'a.currency_code')
            ->orderBy('a.currency_code')
            ->get();

        $lastRate        = ExchangeRate::latest('fetched_at')->first();
        $activeCorridors = \App\Models\PartnerCorridor::where('is_active', true)->count();
        $totalCorridors  = \App\Models\PartnerCorridor::count();

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
                'pending' => \Illuminate\Support\Facades\DB::table('jobs')->count(),
                'failed'  => \Illuminate\Support\Facades\DB::table('failed_jobs')->count(),
            ],
            'transactions' => [
                'pending_transfers'   => Transaction::whereIn('status', ['pending', 'escrowed', 'processing'])->count(),
                'pending_topups'      => \App\Models\TopUp::where('status', 'pending')->count(),
                'pending_withdrawals' => \App\Models\Withdrawal::where('status', 'pending')->count(),
            ],
            'corridors' => [
                'active' => $activeCorridors,
                'total'  => $totalCorridors,
            ],
            'rates' => [
                'last_fetched' => $lastRate?->fetched_at,
                'total'        => ExchangeRate::where('is_active', true)->count(),
            ],
            'account_balances' => $accountBalances,
        ]);
    }

    public function adminAuditLog(Request $request): JsonResponse
    {
        $query = AuditLog::with('user')->orderByDesc('created_at');

        if ($request->filled('action'))      $query->where('action', $request->action);
        if ($request->filled('entity_type')) $query->where('entity_type', $request->entity_type);
        if ($request->filled('user_id'))     $query->where('user_id', $request->user_id);
        if ($request->filled('from'))        $query->whereDate('created_at', '>=', $request->from);
        if ($request->filled('to'))          $query->whereDate('created_at', '<=', $request->to);

        if ($request->filled('search')) {
            $query->where('action', 'like', '%' . $request->search . '%');
        }

        return response()->json($query->paginate(50));
    }
}
