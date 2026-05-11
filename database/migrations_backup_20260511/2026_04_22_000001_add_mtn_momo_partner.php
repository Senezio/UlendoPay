<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

return new class extends Migration
{
    /**
     * MTN MoMo covers currencies PawaPay does not support.
     * ZMW and ZAR are intentionally excluded — PawaPay handles those
     * with higher priority. MTN is the fallback for its exclusive corridors.
     *
     * MTN-exclusive receive currencies: GHS, UGX, RWF, XAF, XOF
     * All PawaPay send currencies can route to these via MTN.
     */
    private array $pawaCurrencies = [
        'MWK', 'TZS', 'KES', 'ZMW', 'ZAR', 'MZN', 'BWP', 'ETB', 'MGA',
    ];

    private array $mtnExclusiveReceive = [
        'GHS', 'UGX', 'RWF', 'XAF', 'XOF',
    ];

    public function up(): void
    {
        // Fix PawaPay code casing while we're here
        DB::table('partners')
            ->where('code', 'pawapay')
            ->update(['code' => 'PAWAPAY']);

        // Insert MTN MoMo partner
        $mtnId = DB::table('partners')->insertGetId([
            'name'                 => 'MTN MoMo',
            'code'                 => 'MTNMOMO',
            'type'                 => 'mobile_money',
            'country_code'         => 'ZAF', // MTN HQ — multi-country aggregator
            'api_config_encrypted' => Crypt::encrypt(['placeholder' => 'configure_via_env']),
            'timeout_seconds'      => 30,
            'max_retries'          => 3,
            'retry_delay_seconds'  => 60,
            'is_active'            => true,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $corridors = [];
        $now       = now();

        // All PawaPay send currencies → MTN exclusive receive currencies
        foreach ($this->pawaCurrencies as $from) {
            foreach ($this->mtnExclusiveReceive as $to) {
                $corridors[] = [
                    'partner_id'    => $mtnId,
                    'from_currency' => $from,
                    'to_currency'   => $to,
                    'min_amount'    => 100,
                    'max_amount'    => 1000000,
                    'priority'      => 2, // Lower priority than PawaPay (priority 1)
                    'fee_percent'   => 1.5,
                    'fee_flat'      => 0,
                    'is_active'     => true,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }

        // MTN exclusive send currencies → all reachable receive currencies
        $allReceive = array_merge($this->pawaCurrencies, $this->mtnExclusiveReceive);

        foreach ($this->mtnExclusiveReceive as $from) {
            foreach ($allReceive as $to) {
                if ($from === $to) continue;

                $corridors[] = [
                    'partner_id'    => $mtnId,
                    'from_currency' => $from,
                    'to_currency'   => $to,
                    'min_amount'    => 100,
                    'max_amount'    => 1000000,
                    'priority'      => 2,
                    'fee_percent'   => 1.5,
                    'fee_flat'      => 0,
                    'is_active'     => true,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }

        DB::table('partner_corridors')->insert($corridors);
    }

    public function down(): void
    {
        $mtn = DB::table('partners')->where('code', 'MTNMOMO')->first();

        if ($mtn) {
            DB::table('partner_corridors')->where('partner_id', $mtn->id)->delete();
            DB::table('partners')->where('id', $mtn->id)->delete();
        }

        // Revert PawaPay code casing
        DB::table('partners')
            ->where('code', 'PAWAPAY')
            ->update(['code' => 'pawapay']);
    }
};
