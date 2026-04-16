<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Account;

class SystemAccountSeeder extends Seeder
{
    /**
     * Currencies supported by the platform.
     * Add new currencies here — accounts will be provisioned automatically.
     */
    private array $currencies = [
        'MWK', 'KES', 'TZS', 'ZMW', 'ZAR', 'MZN', 'BWP', 'ETB', 'MGA'
    ];

    public function run(): void
    {
        $created = 0;
        $skipped = 0;

        // ----------------------------------------------------------------
        // 1. Per-currency system accounts
        //    ESCROW-{CUR}, FEE-{CUR}, PARTNER-{CUR}, {CUR}-POOL
        // ----------------------------------------------------------------
        foreach ($this->currencies as $currency) {
            $perCurrencyAccounts = [
                [
                    'code'           => "ESCROW-{$currency}",
                    'type'           => 'escrow',
                    'currency_code'  => $currency,
                    'normal_balance' => 'debit',
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
                    'normal_balance' => 'debit',
                    'corridor'       => null,
                ],
            ];

            foreach ($perCurrencyAccounts as $data) {
                [$wasCreated] = $this->createAccount($data);
                $wasCreated ? $created++ : $skipped++;
            }
        }

        // ----------------------------------------------------------------
        // 2. Per-corridor guarantee accounts
        //    GUARANTEE-{FROM}-{TO}, currency = from_currency
        //    One for every ordered pair (FROM != TO)
        // ----------------------------------------------------------------
        foreach ($this->currencies as $from) {
            foreach ($this->currencies as $to) {
                if ($from === $to) continue;

                [$wasCreated] = $this->createAccount([
                    'code'           => "GUARANTEE-{$from}-{$to}",
                    'type'           => 'guarantee',
                    'currency_code'  => $from,
                    'normal_balance' => 'debit',
                    'corridor'       => "{$from}-{$to}",
                ]);

                $wasCreated ? $created++ : $skipped++;
            }
        }

        $this->command->info("SystemAccountSeeder complete: {$created} created, {$skipped} already existed.");
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

            // Always ensure a balance row exists, even for pre-existing accounts
            if (!$account->balance()->exists()) {
                $account->balance()->create([
                    'balance'       => 0,
                    'currency_code' => $data['currency_code'],
                ]);
            }
        });

        return [$wasCreated];
    }
}
