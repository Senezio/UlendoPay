import re

path = '/home/malawihi/domains/ulendopay.malawihire.com/backend/app/Http/Controllers/Api/AdminController.php'

with open(path, 'r') as f:
    content = f.read()

old = '''        $days = (int) $request->input('days', 30);
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
            $rev     = (float) \\App\\Models\\JournalEntry::whereHas('account', fn($q) => $q->where('type', 'fee'))
                ->where('entry_type', 'credit')
                ->whereDate('posted_at', $date)
                ->sum('amount');
            $tpCount = \\App\\Models\\TopUp::whereDate('created_at', $date)
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
        $accounts = \\App\\Models\\Account::whereIn('type', ['fee', 'guarantee', 'escrow'])
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
        ]);'''

new = '''        $days = (int) $request->input('days', 30);
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
            $currencies = \\App\\Models\\Account::where('type', 'fee')
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

                $rev = (float) \\App\\Models\\JournalEntry::whereHas('account', fn($q) =>
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
            $topups[]       = \\App\\Models\\TopUp::whereDate('created_at', $date)
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
        $accounts = \\App\\Models\\Account::whereIn('type', ['fee', 'guarantee', 'escrow'])
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
        ]);'''

if old in content:
    content = content.replace(old, new)
    with open(path, 'w') as f:
        f.write(content)
    print('Analytics method patched successfully')
else:
    print('ERROR: target string not found')
