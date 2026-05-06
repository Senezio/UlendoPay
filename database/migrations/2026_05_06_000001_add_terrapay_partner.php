<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

return new class extends Migration
{
    /**
     * TerraPay supports bank-connected transfers across all corridors.
     * It is seeded with priority 3 — lower than PawaPay (1) and MTN MoMo (2).
     * This means it acts as the bank transfer fallback for any corridor
     * that mobile money partners cannot cover, and is the primary partner
     * for corridors where only bank delivery is available.
     *
     * Corridors are generated from all known send currencies × all known
     * receive currencies (excluding same-currency pairs). When the client
     * onboards with TerraPay, specific corridors can be deactivated or
     * adjusted via the admin panel — no migration needed.
     */
    private array $currencies = [
        'MWK', 'TZS', 'KES', 'ZMW', 'ZAR', 'MZN', 'BWP', 'ETB', 'MGA',
        'GHS', 'UGX', 'RWF', 'XAF', 'XOF', 'NGN', 'GBP', 'USD', 'EUR',
        'ZAR', 'INR', 'AED', 'CAD', 'AUD',
    ];

    public function up(): void
    {
        $terraId = DB::table('partners')->insertGetId([
            'name'                => 'TerraPay',
            'code'                => 'TERRAPAY',
            'type'                => 'bank',
            'country_code'        => 'GBR',
            'api_config_encrypted'          => Crypt::encrypt([
                'placeholder' => 'configure_via_env',
            ]),
            'timeout_seconds'     => 30,
            'max_retries'         => 3,
            'retry_delay_seconds' => 60,
            'is_active'           => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $currencies = array_unique($this->currencies);
        $corridors  = [];
        $now        = now();

        foreach ($currencies as $from) {
            foreach ($currencies as $to) {
                if ($from === $to) continue;

                $corridors[] = [
                    'partner_id'    => $terraId,
                    'from_currency' => $from,
                    'to_currency'   => $to,
                    'min_amount'    => 1,
                    'max_amount'    => 10000000,
                    'priority'      => 3,
                    'fee_percent'   => 1.5,
                    'fee_flat'      => 0,
                    'is_active'     => true,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }

        // Insert in chunks to avoid hitting DB limits
        foreach (array_chunk($corridors, 100) as $chunk) {
            DB::table('partner_corridors')->insert($chunk);
        }
    }

    public function down(): void
    {
        $terra = DB::table('partners')->where('code', 'TERRAPAY')->first();

        if ($terra) {
            DB::table('partner_corridors')->where('partner_id', $terra->id)->delete();
            DB::table('partners')->where('id', $terra->id)->delete();
        }
    }
};
