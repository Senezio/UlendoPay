<?php

namespace App\Services\Compliance;

use App\Models\User;
use App\Models\Wallet;
use App\Models\SanctionsEntry;
use App\Models\PepEntry;
use App\Models\ComplianceScreen;
use App\Models\ComplianceAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComplianceService
{
    public function __construct(private NameNormalizer $normalizer) {}

    public function screenSanctions(User $user, string $trigger): ComplianceScreen
    {
        $inputName       = $user->name;
        $normalizedInput = $this->normalizer->normalize($inputName);

        if (empty($normalizedInput)) {
            return $this->recordClearScreen($user, 'sanctions', $inputName, $trigger);
        }

        $bestScore    = 0;
        $bestNameScore = 0;
        $bestEntry    = null;
        $aliasMatch   = false;
        $countryMatch = false;

        $tokens = array_slice(explode(' ', $normalizedInput), 0, 3);

        $candidateIds = collect();
        foreach ($tokens as $token) {
            if (strlen($token) < 3) continue;
            $ids = SanctionsEntry::where('active', true)
                ->where('normalized_name', 'LIKE', $token . '%')
                ->limit(300)
                ->pluck('id');
            $candidateIds = $candidateIds->merge($ids);
        }

        $candidates = SanctionsEntry::whereIn('id', $candidateIds->unique())
            ->select(['id', 'name', 'normalized_name', 'aliases', 'country_codes', 'date_of_birth'])
            ->get();

        foreach ($candidates as $entry) {
            $result            = $this->normalizer->scoreAgainstEntry($normalizedInput, $entry->normalized_name, $entry->aliases ?? []);
            $nameScore         = $result['score'];
            $entryCountryMatch = $user->country_code && !empty($entry->country_codes)
                && in_array($user->country_code, (array) $entry->country_codes);

            // DOB comparison — null means unknown, true means match, false means conflict
            $entryDobMatch = null;
            if ($user->date_of_birth && $entry->date_of_birth) {
                $entryDobMatch = $user->date_of_birth === $entry->date_of_birth->format('Y-m-d');
            }

            // Combined confidence score — name is primary, DOB and country are boosters
            // DOB conflict is a strong signal of different person — penalize heavily
            $combinedScore = $nameScore;

            // First name divergence check — if first tokens are very different,
            // this is likely a family member not the same person.
            // Reduce DOB/country boost effectiveness when first names diverge strongly.
            $inputTokens  = explode(' ', $normalizedInput);
            $entryTokens  = explode(' ', $entry->normalized_name);
            $firstNameA   = $inputTokens[0] ?? '';
            $firstNameB   = $entryTokens[0] ?? '';
            similar_text($firstNameA, $firstNameB, $firstNamePct);
            $firstNameDiverged = $firstNamePct < 40.0;

            if ($entryDobMatch === true) {
                // If first names diverge strongly, DOB match gives less boost
                // Same DOB different first name = likely relative, not same person
                $combinedScore = min(100, $combinedScore + ($firstNameDiverged ? 5 : 15));
            }
            if ($entryDobMatch === false)    $combinedScore = max(0, $combinedScore - 30);
            if ($entryCountryMatch === true) {
                // Country match boost also reduced when first names diverge
                $combinedScore = min(100, $combinedScore + ($firstNameDiverged ? 3 : 10));
            }

            if ($combinedScore > $bestScore) {
                $bestScore     = $combinedScore;
                $bestNameScore = $nameScore;
                $bestEntry     = $entry;
                $aliasMatch    = $result['alias_match'];
                $countryMatch  = $entryCountryMatch;
                $dobMatch      = $entryDobMatch;
            }
        }

        // Use raw name score for decision, not boosted combined score
        $result = $this->determineSanctionsResult($nameScore ?? $bestScore, $aliasMatch, $countryMatch, $bestEntry, $dobMatch ?? null);

        return DB::transaction(function () use ($user, $inputName, $trigger, $bestScore, $bestEntry, $aliasMatch, $countryMatch, $result) {
            $screen = $this->recordScreen($user, 'sanctions', $inputName, $bestScore, $bestEntry?->id, null, $result, $trigger, $aliasMatch, $countryMatch, $bestEntry?->name);

            if ($result === 'blocked') {
                $this->suspendUser($user);
                $this->freezeAllWallets($user);
                $this->createAlert($user, $screen, 'sanctions_match', 'critical', $bestScore, $bestEntry->name, $trigger);
                Log::critical('Compliance: user blocked', ['user_id' => $user->id, 'matched_name' => $bestEntry->name, 'score' => $bestScore, 'trigger' => $trigger]);
            } elseif ($result === 'flagged') {
                $this->createAlert($user, $screen, 'sanctions_match', 'high', $bestScore, $bestEntry?->name, $trigger);
                Log::warning('Compliance: user flagged', ['user_id' => $user->id, 'matched_name' => $bestEntry?->name, 'score' => $bestScore, 'trigger' => $trigger]);
            }

            $user->update(['last_screened_at' => now()]);
            return $screen;
        });
    }

    public function screenPep(User $user, string $trigger): ComplianceScreen
    {
        $inputName       = $user->name;
        $normalizedInput = $this->normalizer->normalize($inputName);

        if (empty($normalizedInput)) {
            return $this->recordClearScreen($user, 'pep', $inputName, $trigger);
        }

        $bestScore  = 0;
        $bestEntry  = null;
        $aliasMatch = false;

        $tokens = array_slice(explode(' ', $normalizedInput), 0, 3);

        $candidateIds = collect();
        foreach ($tokens as $token) {
            if (strlen($token) < 3) continue;
            $ids = PepEntry::where('active', true)
                ->where('normalized_name', 'LIKE', $token . '%')
                ->limit(300)
                ->pluck('id');
            $candidateIds = $candidateIds->merge($ids);
        }

        $candidates = PepEntry::whereIn('id', $candidateIds->unique())
            ->select(['id', 'name', 'normalized_name', 'aliases', 'country_code', 'risk_level', 'date_of_birth'])
            ->get();

        $dobMatch     = null;
        $countryMatch = false;
        foreach ($candidates as $entry) {
            $result        = $this->normalizer->scoreAgainstEntry($normalizedInput, $entry->normalized_name, $entry->aliases ?? []);
            $nameScore     = $result['score'];
            $entryCountry  = $user->country_code && $entry->country_code
                && strtoupper($user->country_code) === strtoupper($entry->country_code);

            // DOB comparison for PEP
            $entryDobMatch = null;
            if ($user->date_of_birth && $entry->date_of_birth) {
                $entryDobMatch = $user->date_of_birth === $entry->date_of_birth->format('Y-m-d');
            }

            // Combined score — name primary, DOB and country as boosters
            $combinedScore = $nameScore;

            // First name divergence check
            $inputTokens   = explode(' ', $normalizedInput);
            $entryTokens   = explode(' ', $entry->normalized_name);
            $firstNameA    = $inputTokens[0] ?? '';
            $firstNameB    = $entryTokens[0] ?? '';
            similar_text($firstNameA, $firstNameB, $firstNamePct);
            $firstNameDiverged = $firstNamePct < 40.0;

            if ($entryDobMatch === true) {
                $combinedScore = min(100, $combinedScore + ($firstNameDiverged ? 5 : 15));
            }
            if ($entryDobMatch === false) $combinedScore = max(0,   $combinedScore - 30);
            if ($entryCountry === true) {
                $combinedScore = min(100, $combinedScore + ($firstNameDiverged ? 3 : 10));
            }

            if ($combinedScore > $bestScore) {
                $bestScore    = $combinedScore;
                $bestEntry    = $entry;
                $aliasMatch   = $result['alias_match'];
                $dobMatch     = $entryDobMatch;
                $countryMatch = $entryCountry;
            }
        }

        // DOB conflict downgrades a soft PEP match to clear
        if ($dobMatch === false) {
            $result = 'clear';
        } else {
            $result = ($bestScore >= 60) ? 'flagged' : 'clear';
        }

        return DB::transaction(function () use ($user, $inputName, $trigger, $bestScore, $bestEntry, $aliasMatch, $result) {
            $screen = $this->recordScreen($user, 'pep', $inputName, $bestScore, null, $bestEntry?->id, $result, $trigger, $aliasMatch, false, $bestEntry?->name);

            if ($result === 'flagged') {
                $severity = match($bestEntry?->risk_level) {
                    'high'   => 'critical',
                    'medium' => 'high',
                    default  => 'medium',
                };
                $this->createAlert($user, $screen, 'pep_match', $severity, $bestScore, $bestEntry?->name, $trigger);
                Log::warning('Compliance: PEP match', ['user_id' => $user->id, 'matched_name' => $bestEntry?->name, 'score' => $bestScore, 'trigger' => $trigger]);
            }

            $user->update(['last_screened_at' => now()]);
            return $screen;
        });
    }

    public function fullScreen(User $user, string $trigger): array
    {
        return [
            'sanctions' => $this->screenSanctions($user, $trigger),
            'pep'       => $this->screenPep($user, $trigger),
        ];
    }

    private function determineSanctionsResult(int $score, bool $aliasMatch, bool $countryMatch, ?SanctionsEntry $entry, ?bool $dobMatch = null): string
    {
        if ($entry === null) return 'clear';

        // Never block if DOB explicitly conflicts
        if ($dobMatch === false) return 'clear';

        // Hard block only with strong multi-signal confirmation
        if (
            $score >= 90 &&
            (
                $aliasMatch === true ||
                $dobMatch === true
            )
        ) {
            return 'blocked';
        }

        // Strong name + DOB + country
        if (
            $score >= 85 &&
            $dobMatch === true &&
            $countryMatch === true
        ) {
            return 'blocked';
        }

        // Everything else above threshold is manual review
        if ($score >= 60) {
            return 'flagged';
        }

        return 'clear';
    }

    private function suspendUser(User $user): void
    {
        if ($user->status !== 'suspended') {
            $user->update(['status' => 'suspended']);
        }
    }

    private function freezeAllWallets(User $user): void
    {
        Wallet::where('user_id', $user->id)->where('status', 'active')->update(['status' => 'frozen']);
    }

    private function recordClearScreen(User $user, string $screenType, string $inputName, string $trigger): ComplianceScreen
    {
        return $this->recordScreen($user, $screenType, $inputName, 0, null, null, 'clear', $trigger, false, false, null);
    }

    private function recordScreen(User $user, string $screenType, string $inputName, int $score, ?int $sanctionsEntryId, ?int $pepEntryId, string $result, string $trigger, bool $aliasMatch, bool $countryMatch, ?string $matchedName): ComplianceScreen
    {
        return ComplianceScreen::create([
            'user_id'            => $user->id,
            'screen_type'        => $screenType,
            'input_name'         => $inputName,
            'match_score'        => $score,
            'sanctions_entry_id' => $sanctionsEntryId,
            'pep_entry_id'       => $pepEntryId,
            'result'             => $result,
            'action_taken'       => $result === 'blocked' ? 'account_suspended_wallets_frozen' : ($result === 'flagged' ? 'flagged_for_review' : null),
            'match_details'      => ['algorithm' => 'similar_text+soundex+token_prefix', 'score' => $score, 'alias_match' => $aliasMatch, 'country_match' => $countryMatch, 'dob_match' => $dobMatch ?? null, 'version' => '1.2'],
            'triggered_by'       => $trigger,
            'screened_at'        => now(),
        ]);
    }

    private function createAlert(User $user, ComplianceScreen $screen, string $alertType, string $severity, int $score, ?string $matchedName, string $trigger): void
    {
        ComplianceAlert::create([
            'user_id'              => $user->id,
            'compliance_screen_id' => $screen->id,
            'alert_type'           => $alertType,
            'severity'             => $severity,
            'match_score'          => $score,
            'matched_name'         => $matchedName,
            'status'               => 'new',
            'triggered_by'         => $trigger,
        ]);
    }
}
