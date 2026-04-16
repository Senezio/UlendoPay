<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PartnerCorridor;

class CorridorSeeder extends Seeder
{
    public function run(): void
    {
        $currencies = ['MWK','KES','TZS','ZMW','ZAR','MZN','BWP','ETB','MGA'];
        $added = 0;

        foreach ($currencies as $from) {
            foreach ($currencies as $to) {
                if ($from === $to) continue;

                PartnerCorridor::firstOrCreate(
                    ['from_currency' => $from, 'to_currency' => $to],
                    [
                        'partner_id'  => 1,
                        'min_amount'  => 100,
                        'max_amount'  => 500000,
                        'priority'    => 1,
                        'fee_percent' => 1.5,
                        'fee_flat'    => 0,
                        'is_active'   => true,
                    ]
                );
                $added++;
            }
        }

        echo "Seeded {$added} corridor pairs.\n";
    }
}
