<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Account;

class SystemAccountSeeder extends Seeder
{
    /**
     * Currencies are derived dynamically from partner_corridors.
     * This means adding a new corridor automatically provisions
     * all required system accounts when the seeder is re-run.
     * No hardcoded currency list — the corridors table is the source of truth.
     */
    public function run(): void
    {
        $created = 0;
        $skipped = 0;

        // ── Derive all supported currencies from partner_corridors ────────────
        // Union of from_currency and to_currency gives every currency
        // the platform can send or receive in.
        $fromCurrencies = DB::table('partner_corridors')
            ->distinct()
            ->pluck('from_currency');

        $toCurrencies = DB::table('partner_corridors')
            ->distinct()
            ->pluck('to_currency');

        $currencies = $fromCurrencies
            ->merge($toCurrencies)
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        if (empty($currencies)) {
            $this->command->warn(
                'No currencies found in partner_corridors. ' .
                'Run PartnerSeeder first, then re-run this seeder.'
            );
            return;
        }

        $this->command->info(
            'Provisioning system accounts for ' . count($currencies) .
            ' currencies: ' . implode(', ', $currencies)
        );

        // ── 1. Per-currency system accounts ───────────────────────────────────
        // For each currency, ensure the following accounts exist:
        //
        //   ESCROW-{CCY}   escrow    credit  — funds held during cross-currency transfer
        //   FEE-{CCY}      fee       credit  — platform revenue collected
        //   PARTNER-{CCY}  partner   credit  — settlement obligations to partners
        //   {CCY}-POOL     system    credit  — internal transit/holding account
        //   {CCY}-EQUITY   system    credit  — source of float capital / retained earnings
        //
        // All accounts use credit normal_balance because this is a liability-centric
        // ledger — the platform records what it owes, not what it holds.
        // Real assets (partner receivables, bank accounts) will be tracked separately
        // when the asset accounting layer is implemented.

        foreach ($currencies as $currency) {
            $perCurrencyAccounts = [
                [
                    'code'           => "ESCROW-{$currency}",
                    'type'           => 'escrow',
                    'currency_code'  => $currency,
                    'normal_balance' => 'credit',
                    'corridor'       => null,
                ],
                [
                    'code'           => "FEE-{$currency}",
                    'type'           => 'fee',
                    'currency_code'  => $currency,
                    'normal_balance' => 'credit',
                    'corridor'       => null,
                ],
                [
                    'code'           => "PARTNER-{$currency}",
                    'type'           => 'partner',
                    'currency_code'  => $currency,
                    'normal_balance' => 'credit',
                    'corridor'       => null,
                ],
                [
                    'code'           => "{$currency}-POOL",
                    'type'           => 'system',
                    'currency_code'  => $currency,
                    'normal_balance' => 'credit',
                    'corridor'       => null,
                ],
                [
                    'code'           => "{$currency}-EQUITY",
                    'type'           => 'system',
                    'currency_code'  => $currency,
                    'normal_balance' => 'credit',
                    'corridor'       => null,
                ],
            ];

            foreach ($perCurrencyAccounts as $data) {
                [$wasCreated] = $this->createAccount($data);
                $wasCreated ? $created++ : $skipped++;
            }
        }

        // ── 2. Per-corridor guarantee accounts ────────────────────────────────
        // One guarantee account per ordered corridor pair (FROM → TO, FROM ≠ TO).
        // currency_code = from_currency (the currency being held as guarantee).
        // Only creates accounts for corridors that exist in partner_corridors.

        $corridors = DB::table('partner_corridors')
            ->distinct()
            ->select('from_currency', 'to_currency')
            ->get();

        foreach ($corridors as $corridor) {
            $from = $corridor->from_currency;
            $to   = $corridor->to_currency;

            if ($from === $to) continue;

            [$wasCreated] = $this->createAccount([
                'code'           => "GUARANTEE-{$from}-{$to}",
                'type'           => 'guarantee',
                'currency_code'  => $from,
                'normal_balance' => 'credit',
                'corridor'       => "{$from}-{$to}",
            ]);

            $wasCreated ? $created++ : $skipped++;
        }

        $this->command->info(
            "SystemAccountSeeder complete: {$created} created, {$skipped} already existed."
        );
    }

    /**
     * Create an account + its balance row atomically.
     * Uses firstOrCreate so it is safe to re-run at any time.
     *
     * @return array{bool, Account}  [wasJustCreated, account]
     */
    private function createAccount(array $data): array
    {
        $wasCreated = false;

        DB::transaction(function () use ($data, &$wasCreated) {
            $account = Account::firstOrCreate(
                ['code' => $data['code']],
                [
                    'type'           => $data['type'],
                    'currency_code'  => $data['currency_code'],
                    'normal_balance' => $data['normal_balance'],
                    'corridor'       => $data['corridor'],
                    'owner_id'       => null,
                    'owner_type'     => null,
                    'is_active'      => true,
                ]
            );

            $wasCreated = $account->wasRecentlyCreated;

            // Always ensure a balance row exists
            if (! $account->balance()->exists()) {
                $account->balance()->create([
                    'balance'       => 0,
                    'currency_code' => $data['currency_code'],
                ]);
            }
        });

        return [$wasCreated];
    }
}
