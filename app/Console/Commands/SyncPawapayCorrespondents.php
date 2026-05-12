<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Models\PartnerOperator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncPawapayCorrespondents extends Command
{
    protected $signature   = 'pawapay:sync-correspondents';
    protected $description = 'Sync active PawaPay correspondents from the PawaPay API';

    public function handle(): int
    {
        $baseUrl  = config('services.pawapay.base_url');
        $apiToken = config('services.pawapay.api_token');

        if (empty($apiToken)) {
            $this->error('[pawapay:sync] API token not configured.');
            return self::FAILURE;
        }

        $this->line('[pawapay:sync] Fetching active correspondents from PawaPay...');

        try {
            $response = Http::withToken($apiToken)
                ->timeout(30)
                ->get("{$baseUrl}/v1/active-conf");

            if (!$response->successful()) {
                throw new \RuntimeException(
                    "PawaPay API returned HTTP {$response->status()}"
                );
            }

            $data    = $response->json();
            $partner = Partner::where('code', 'PAWAPAY')->firstOrFail();
            $now     = now();
            $synced  = 0;

            foreach ($data['countries'] ?? [] as $countryData) {
                $country = $countryData['country'];

                foreach ($countryData['correspondents'] ?? [] as $correspondentData) {
                    $correspondent = $correspondentData['correspondent'];
                    $currency      = $correspondentData['currency'];

                    foreach ($correspondentData['operationTypes'] ?? [] as $opType) {
                        PartnerOperator::updateOrCreate(
                            [
                                'partner_id'     => $partner->id,
                                'correspondent'  => $correspondent,
                                'operation_type' => $opType['operationType'],
                            ],
                            [
                                'country'        => $country,
                                'currency'       => $currency,
                                'min_amount'     => (float) $opType['minTransactionLimit'],
                                'max_amount'     => (float) $opType['maxTransactionLimit'],
                                'is_active'      => true,
                                'last_synced_at' => $now,
                            ]
                        );

                        $synced++;
                    }
                }
            }

            $this->line("[pawapay:sync] Synced {$synced} correspondent operation records.");

            Log::info('[pawapay:sync] Correspondents synced', ['count' => $synced]);

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("[pawapay:sync] Failed: {$e->getMessage()}");

            Log::error('[pawapay:sync] Sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
