<?php

namespace Database\Seeders;

use App\Models\Partner;
use App\Models\WebhookSignature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

class WebhookSignatureSeeder extends Seeder
{
    public function run(): void
    {
        // ── PawaPay ──────────────────────────────────────────────────────
        // PawaPay uses RFC-9421 HTTP signatures with ECDSA P-256 SHA-256.
        // The public key is stored encrypted at rest and read at runtime
        // via config('services.pawapay.public_key') → PAWAPAY_PUBLIC_KEY env.
        // Never hardcode the key here — always read from config.
        $pawa = Partner::where('code', 'PAWAPAY')->first();

        if (!$pawa) {
            $this->command->error('WebhookSignatureSeeder: PAWAPAY partner not found — run PartnerSeeder first.');
            return;
        }

        $publicKey = config('services.pawapay.public_key');

        if (empty($publicKey)) {
            $this->command->error('WebhookSignatureSeeder: PAWAPAY_PUBLIC_KEY is not set in .env — skipping.');
            return;
        }

        WebhookSignature::updateOrCreate(
            [
                'partner_id' => $pawa->id,
            ],
            [
                'secret_encrypted' => Crypt::encrypt($publicKey),
                'algorithm'        => 'ecdsa-p256-sha256',
                'is_active'        => true,
                'rotated_at'       => now(),
            ]
        );

        $this->command->info('PawaPay: webhook signature seeded (ecdsa-p256-sha256).');
    }
}
