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
        // Create PawaPay Partner
        $pawa = Partner::updateOrCreate(
            ['code' => 'pawapay'],
            [
                'name' => 'PawaPay',
                'type' => 'mobile_money',
                'country_code' => 'MWI', // Matches currencyToCountry mapping
                'api_config_encrypted' => Crypt::encrypt(['api_key' => 'seed_test_key']),
                'is_active' => true,
            ]
        );

        // Seed the 8 Supported Corridors from PawapayPartner.php
        $corridors = [
            'TZS', 'KES', 'ZMW', 'ZAR', 'MZN', 'BWP', 'ETB', 'MGA'
        ];

        foreach ($corridors as $currency) {
            PartnerCorridor::updateOrCreate(
                [
                    'partner_id' => $pawa->id,
                    'from_currency' => 'MWK',
                    'to_currency' => $currency,
                ],
                [
                    'min_amount' => 1000,
                    'max_amount' => 1000000,
                    'priority' => 1,
                    'is_active' => true,
                ]
            );
        }
        
        // Add domestic corridor for top-ups/withdrawals if needed
        PartnerCorridor::updateOrCreate(
            ['partner_id' => $pawa->id, 'from_currency' => 'MWK', 'to_currency' => 'MWK'],
            ['min_amount' => 500, 'max_amount' => 2000000, 'priority' => 1, 'is_active' => true]
        );
    }
}
