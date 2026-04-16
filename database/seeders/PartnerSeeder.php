<?php

namespace Database\Seeders;

use App\Models\Partner;
use App\Models\PartnerCorridor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

class PartnerSeeder extends Seeder
{
    public function run(): void
    {
        // ── PawaPay ──────────────────────────────────────────────────────
        // Operates in all directions between its supported currencies.
        // Add new currencies here as PawaPay expands coverage.
        $pawaPayCurrencies = [
            'MWK', 'TZS', 'KES', 'ZMW', 'ZAR', 'MZN', 'BWP', 'ETB', 'MGA'
        ];

        $pawa = Partner::updateOrCreate(
            ['code' => 'pawapay'],
            [
                'name'                  => 'PawaPay',
                'type'                  => 'mobile_money',
                'country_code'          => 'MWI',
                'api_config_encrypted'  => Crypt::encrypt(['api_key' => 'seed_test_key']),
                'is_active'             => true,
            ]
        );

        $created = 0;
        $skipped = 0;

        // Full mesh — all ordered pairs (from != to)
        foreach ($pawaPayCurrencies as $from) {
            foreach ($pawaPayCurrencies as $to) {
                if ($from === $to) continue;

                $corridor = PartnerCorridor::updateOrCreate(
                    [
                        'partner_id'    => $pawa->id,
                        'from_currency' => $from,
                        'to_currency'   => $to,
                    ],
                    [
                        'min_amount'  => 100,
                        'max_amount'  => 1000000,
                        'priority'    => 1,
                        'fee_percent' => 1.5,
                        'fee_flat'    => 0,
                        'is_active'   => true,
                    ]
                );

                $corridor->wasRecentlyCreated ? $created++ : $skipped++;
            }
        }

        $this->command->info("PawaPay: {$created} corridors created, {$skipped} already existed.");
    }
}
