<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TransferTier;

class TierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'name'                  => 'unverified',
                'level'                 => 0,
                'label'                 => 'Unverified',
                'daily_limit'           => 25,
                'monthly_limit'         => 100,
                'per_transaction_limit' => 10,
                'fee_discount_percent'  => 0,
                'limit_currency'        => 'USD',
                'is_active'             => true,
            ],
            [
                'name'                  => 'basic',
                'level'                 => 1,
                'label'                 => 'Basic',
                'daily_limit'           => 250,
                'monthly_limit'         => 1000,
                'per_transaction_limit' => 100,
                'fee_discount_percent'  => 0,
                'limit_currency'        => 'USD',
                'is_active'             => true,
            ],
            [
                'name'                  => 'standard',
                'level'                 => 2,
                'label'                 => 'Standard',
                'daily_limit'           => 1000,
                'monthly_limit'         => 5000,
                'per_transaction_limit' => 500,
                'fee_discount_percent'  => 10,
                'limit_currency'        => 'USD',
                'is_active'             => true,
            ],
            [
                'name'                  => 'premium',
                'level'                 => 3,
                'label'                 => 'Premium',
                'daily_limit'           => 5000,
                'monthly_limit'         => 25000,
                'per_transaction_limit' => 2500,
                'fee_discount_percent'  => 20,
                'limit_currency'        => 'USD',
                'is_active'             => true,
            ],
        ];

        foreach ($tiers as $tier) {
            TransferTier::firstOrCreate(
                ['name' => $tier['name']],
                $tier
            );
        }

        $this->command->info('Tiers seeded: ' . count($tiers) . ' tiers created.');
    }
}
