<?php

namespace App\Console\Commands;

use App\Services\Compliance\NameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSanctionsLists extends Command
{
    protected $signature = 'compliance:sync-lists {--dry-run : Preview counts without writing to database}';
    protected $description = 'Sync sanctions and PEP lists from OpenSanctions';

    private const SANCTIONS_URL = 'https://data.opensanctions.org/datasets/latest/sanctions/targets.simple.csv';
    private const PEP_URL       = 'https://data.opensanctions.org/datasets/latest/peps/targets.simple.csv';
    private const SOURCE        = 'opensanctions';
    private const CHUNK_SIZE    = 500;

    private const INDIVIDUAL_SCHEMAS = ['Person'];
    private const ENTITY_SCHEMAS     = ['Organization', 'Company', 'LegalEntity', 'PublicBody'];
    private const SKIP_SCHEMAS       = ['Vessel', 'Airplane', 'Security', 'Address', 'CryptoWallet'];

    // Not real ISO-3166-1 alpha-2 country codes
    private const PSEUDO_COUNTRY_CODES = ['eu', 'un', 'xk', 'xx', 'xi'];

    public function handle(NameNormalizer $normalizer): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN - no data will be written.');
        }

        $this->info('--- Sanctions list ---');
        [$count, $skipped] = $this->syncSanctions($normalizer, $dryRun);
        $this->info("Sanctions: {$count} imported, {$skipped} skipped.");

        $this->info('--- PEP list ---');
        [$count, $skipped] = $this->syncPep($normalizer, $dryRun);
        $this->info("PEP: {$count} imported, {$skipped} skipped.");

        $this->info('Sync complete.');
        return 0;
    }

    // =========================================================================
    // Sanctions
    // =========================================================================

    private function syncSanctions(NameNormalizer $normalizer, bool $dryRun): array
    {
        $rows    = $this->fetchCsv(self::SANCTIONS_URL);
        $count   = 0;
        $skipped = 0;
        $buffer  = [];
        $seen    = [];
        $now     = now()->toDateTimeString();

        foreach ($rows as $row) {
            $schema = trim($row['schema'] ?? '');

            // Skip non-person, non-entity schemas
            if (in_array($schema, self::SKIP_SCHEMAS, true)) {
                $skipped++;
                continue;
            }

            $entityType = in_array($schema, self::INDIVIDUAL_SCHEMAS, true)
                ? 'individual'
                : 'entity';

            $name = trim($row['name'] ?? '');
            if ($name === '') {
                $skipped++;
                continue;
            }

            $normalizedName = $normalizer->normalize($name);
            if ($normalizedName === '') {
                $skipped++;
                continue;
            }

            $listRef = $row['id'] ?? '';
            if ($listRef === '' || isset($seen[$listRef])) {
                $skipped++;
                continue;
            }
            $seen[$listRef] = true;

            $aliases      = $this->splitDelimited($row['aliases'] ?? '');
            $countryCodes = $this->cleanCountryCodes($this->splitDelimited($row['countries'] ?? ''));
            $dob          = $this->parseDate($row['birth_date'] ?? '');

            $buffer[] = [
                'source'          => self::SOURCE,
                'entity_type'     => $entityType,
                'name'            => $name,
                'normalized_name' => $normalizedName,
                'aliases'         => json_encode($normalizer->normalizeAliases($aliases)),
                'country_codes'   => json_encode($countryCodes),
                'date_of_birth'   => $dob,
                'list_reference'  => $row['id'],
                'metadata'        => json_encode([
                    'schema'      => $schema,
                    'dataset'     => $this->splitDelimited($row['dataset'] ?? ''),
                    'sanctions'   => $this->splitDelimited($row['sanctions'] ?? ''),
                    'program_ids' => $this->splitDelimited($row['program_ids'] ?? ''),
                    'identifiers' => $this->splitDelimited($row['identifiers'] ?? ''),
                ]),
                'active'          => 1,
                'last_synced_at'  => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];

            $count++;

            if (count($buffer) >= self::CHUNK_SIZE) {
                if (!$dryRun) {
                    $this->upsertSanctions($this->deduplicateByKey($buffer, 'list_reference'));
                }
                $buffer = [];
            }
        }

        if (!$dryRun && !empty($buffer)) {
            $this->upsertSanctions($this->deduplicateByKey($buffer, 'list_reference'));
        }

        return [$count, $skipped];
    }

    // =========================================================================
    // PEP
    // =========================================================================

    private function syncPep(NameNormalizer $normalizer, bool $dryRun): array
    {
        $rows    = $this->fetchCsv(self::PEP_URL);
        $count   = 0;
        $skipped = 0;
        $buffer  = [];
        $seen    = [];
        $now     = now()->toDateTimeString();

        foreach ($rows as $row) {
            // PEP list is all Person but guard anyway
            if (trim($row['schema'] ?? '') !== 'Person') {
                $skipped++;
                continue;
            }

            $name = trim($row['name'] ?? '');
            if ($name === '') {
                $skipped++;
                continue;
            }

            $normalizedName = $normalizer->normalize($name);
            if ($normalizedName === '') {
                $skipped++;
                continue;
            }

            $listRef = $row['id'] ?? '';
            if ($listRef === '' || isset($seen[$listRef])) {
                $skipped++;
                continue;
            }
            $seen[$listRef] = true;

            $aliases      = $this->splitDelimited($row['aliases'] ?? '');
            $allCountries = $this->cleanCountryCodes($this->splitDelimited($row['countries'] ?? ''));
            $dob          = $this->parseDate($row['birth_date'] ?? '');

            // Primary country - first real ISO code after filtering pseudo codes
            $primaryCountry = !empty($allCountries) ? $allCountries[0] : null;

            $buffer[] = [
                'source'          => self::SOURCE,
                'list_reference'  => $row['id'],
                'name'            => $name,
                'normalized_name' => $normalizedName,
                'aliases'         => json_encode($normalizer->normalizeAliases($aliases)),
                'country_code'    => $primaryCountry,
                'position'        => null,
                'risk_level'      => 'medium',
                'date_of_birth'   => $dob,
                'metadata'        => json_encode([
                    'dataset'       => $this->splitDelimited($row['dataset'] ?? ''),
                    'all_countries' => $allCountries,
                    'identifiers'   => $this->splitDelimited($row['identifiers'] ?? ''),
                ]),
                'active'          => 1,
                'last_synced_at'  => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];

            $count++;

            if (count($buffer) >= self::CHUNK_SIZE) {
                if (!$dryRun) {
                    $this->upsertPep($this->deduplicateByKey($buffer, 'list_reference'));
                }
                $buffer = [];
            }
        }

        if (!$dryRun && !empty($buffer)) {
            $this->upsertPep($this->deduplicateByKey($buffer, 'list_reference'));
        }

        return [$count, $skipped];
    }

    // =========================================================================
    // Database writes
    // =========================================================================

    private function upsertSanctions(array $records): void
    {
        foreach ($records as $record) {
            DB::table('sanctions_entries')->updateOrInsert(
                ['list_reference' => $record['list_reference']],
                $record
            );
        }
    }

    private function upsertPep(array $records): void
    {
        foreach ($records as $record) {
            DB::table('pep_entries')->updateOrInsert(
                ['list_reference' => $record['list_reference']],
                $record
            );
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fetchCsv(string $url): array
    {
        $this->line("Fetching: {$url}");

        $response = Http::timeout(120)->get($url);

        if (!$response->successful()) {
            $this->error("Failed to fetch {$url} - HTTP {$response->status()}");
            return [];
        }

        // Write to temp file and use fgetcsv to correctly handle
        // multiline quoted fields that explode("\n") breaks
        $tmp = tmpfile();
        fwrite($tmp, $response->body());
        rewind($tmp);

        $headers = fgetcsv($tmp);
        $rows    = [];

        while (($values = fgetcsv($tmp)) !== false) {
            if (count($values) === count($headers)) {
                $rows[] = array_combine($headers, $values);
            }
        }

        fclose($tmp);

        $this->line("Fetched " . count($rows) . " rows.");
        return $rows;
    }

    private function splitDelimited(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(';', $value))));
    }

    private function cleanCountryCodes(array $codes): array
    {
        $cleaned = [];
        foreach ($codes as $code) {
            $code = strtoupper(trim($code));
            // Must be exactly 2 letters and not a pseudo code
            if (strlen($code) === 2 && ctype_alpha($code) && !in_array(strtolower($code), self::PSEUDO_COUNTRY_CODES, true)) {
                $cleaned[] = $code;
            }
        }
        return array_values(array_unique($cleaned));
    }

    private function deduplicateByKey(array $records, string $key): array
    {
        $seen   = [];
        $unique = [];
        foreach ($records as $record) {
            $val = $record[$key] ?? null;
            if ($val !== null && !isset($seen[$val])) {
                $seen[$val] = true;
                $unique[]   = $record;
            }
        }
        return $unique;
    }

    private function parseDate(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        foreach (explode(';', $value) as $candidate) {
            $candidate = trim($candidate);

            // Full date YYYY-MM-DD
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
                return $candidate;
            }

            // Year only e.g. 1961 - preserve as YYYY-01-01
            if (preg_match('/^\d{4}$/', $candidate)) {
                return $candidate . '-01-01';
            }
        }

        return null;
    }
}
