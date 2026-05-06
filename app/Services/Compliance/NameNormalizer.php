<?php

namespace App\Services\Compliance;

class NameNormalizer
{
    /**
     * Noise words to strip before scoring.
     * Covers English titles, French titles common in Francophone Africa.
     */
    private const NOISE_WORDS = [
        'mr', 'mrs', 'ms', 'miss', 'dr', 'prof', 'rev', 'sir',
        'jr', 'sr', 'ii', 'iii', 'iv',
        // French
        'm', 'mme', 'mlle', 'dr', 'pr',
    ];

    /**
     * Common Arabic transliteration substitutions.
     * Maps variant spellings to a canonical form.
     */
    private const ARABIC_SUBSTITUTIONS = [
        'mohammed' => 'muhammad',
        'mohamed'  => 'muhammad',
        'mohamad'  => 'muhammad',
        'mehmet'   => 'muhammad',
        'ahmad'    => 'ahmed',
        'achmed'   => 'ahmed',
        'ali'      => 'ali',
        'al '      => 'al',
        'el '      => 'al',
        'abdel'    => 'abd al',
        'abdal'    => 'abd al',
        'abdu'     => 'abd al',
    ];

    /**
     * Normalize a name for storage or comparison.
     *
     * Steps:
     *  1. Lowercase
     *  2. Transliterate accented/diacritic characters to ASCII
     *  3. Remove non-alpha characters (keep spaces)
     *  4. Apply Arabic transliteration substitutions
     *  5. Strip noise words (titles)
     *  6. Collapse whitespace
     */
    public function normalize(string $name): string
    {
        // 1. Lowercase
        $name = mb_strtolower(trim($name), 'UTF-8');

        // 2. Transliterate diacritics to ASCII (é→e, ç→c, ô→o, etc.)
        $name = $this->removeDiacritics($name);

        // 3. Remove anything that isn't a letter or space
        $name = preg_replace('/[^a-z\s]/', '', $name);

        // 4. Arabic transliteration substitutions
        foreach (self::ARABIC_SUBSTITUTIONS as $variant => $canonical) {
            $name = preg_replace('/\b' . preg_quote($variant, '/') . '\b/', $canonical, $name);
        }

        // 5. Strip noise words
        $parts = explode(' ', $name);
        $parts = array_filter($parts, fn($p) => $p !== '' && !in_array($p, self::NOISE_WORDS, true));

        // 6. Collapse and return
        return implode(' ', array_values($parts));
    }

    /**
     * Normalize an array of alias strings.
     * Enforces that only normalized values are stored — never raw mixed values.
     *
     * @param  string[]  $aliases
     * @return string[]
     */
    public function normalizeAliases(array $aliases): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map(fn($a) => $this->normalize($a), $aliases),
                    function (string $a): bool {
                        if ($a === '') {
                            return false;
                        }
                        // Discard single-token aliases shorter than 4 chars - too ambiguous
                        // e.g. "monica", "ali", "m" are useless for screening
                        if (!str_contains($a, ' ') && mb_strlen($a) < 4) {
                            return false;
                        }
                        // Discard single-token aliases that are common first names only
                        // A meaningful alias must either be multi-token or be a distinctive name
                        // We enforce: single token aliases must be at least 5 chars
                        if (!str_contains($a, ' ') && mb_strlen($a) < 5) {
                            return false;
                        }
                        return true;
                    }
                )
            )
        );
    }

    /**
     * Score similarity between two already-normalized names.
     *
     * Returns 0–100.
     *
     * Strategy:
     *  - Exact match            → 100
     *  - similar_text() score   → weighted 70%
     *  - soundex match          → adds up to 30 points
     *
     * We score each token pair and take the best combination to handle
     * reordered name parts (e.g. "John Banda" vs "Banda John").
     */
    public function score(string $normalizedA, string $normalizedB): int
    {
        if ($normalizedA === $normalizedB) {
            return 100;
        }

        if ($normalizedA === '' || $normalizedB === '') {
            return 0;
        }

        $tokensA = explode(' ', $normalizedA);
        $tokensB = explode(' ', $normalizedB);

        // Single token names — only match against the last token (surname) of the other name
        // A single token like "monica" or "vincent" must not match a first name
        // It must match the surname specifically to be meaningful
        if (count($tokensA) === 1 || count($tokensB) === 1) {
            $singleToken = count($tokensA) === 1 ? $normalizedA : $normalizedB;
            $multiTokens = count($tokensA) === 1 ? $tokensB : $tokensA;
            $surname     = end($multiTokens);

            similar_text($singleToken, $surname, $surnamePct);
            if ($surnamePct < 70.0) {
                return 55; // Single token does not match surname - treat as clear
            }

            // It matches the surname - score normally
            similar_text($normalizedA, $normalizedB, $pct);
            return (int) round($pct);
        }

        // Surname match — use levenshtein normalized by length for accuracy
        // similar_text sees "banda" and "brand" as 80% similar — levenshtein correctly
        // shows they are 2 edits apart on a 5-letter word (40% different)
        $surnameA = end($tokensA);
        $surnameB = end($tokensB);

        $surnamePasses = $this->surnameSimilar($surnameA, $surnameB);

        if (!$surnamePasses) {
            // Last tokens don't match well — check if shorter name's surname
            // matches ANY token in the longer name (Arabic name order handling)
            $shorter      = count($tokensA) <= count($tokensB) ? $tokensA : $tokensB;
            $longer       = count($tokensA) <= count($tokensB) ? $tokensB : $tokensA;
            $shortSurname = end($shorter);
            $altPasses    = false;

            foreach ($longer as $tL) {
                if ($this->surnameSimilar($shortSurname, $tL)) {
                    $altPasses = true;
                    break;
                }
            }

            if (!$altPasses) {
                return 55; // Surname not found anywhere in the other name — clear
            }
        }

        // First name must also match well — surname alone is never enough
        // "Benson Kabila" vs "Joseph Kabila" → different people, should CLEAR
        $firstA = $tokensA[0];
        $firstB = $tokensB[0];
        similar_text($firstA, $firstB, $firstPct);

        if ($firstPct < 55.0) {
            return 55; // First names too different — clear regardless of surname
        }

        // Near-identical surnames (levenshtein 1-2) — cap at FLAG
        // e.g. Johnson/Johnston — compliance officer must review
        $surnameEditDist = levenshtein($surnameA, $surnameB);
        if ($surnameEditDist <= 2 && $surnameA !== $surnameB) {
            $capAt84 = true;
        }

        // Score tokens in SHORTER name against best match in LONGER name
        // Extra tokens in longer name get partial credit (0.5 weight) not full penalty
        // This allows "Ali Hassan" to still match "Ali Hassan Mohamed" reasonably
        $longer  = count($tokensA) >= count($tokensB) ? $tokensA : $tokensB;
        $shorter = count($tokensA) >= count($tokensB) ? $tokensB : $tokensA;

        // Score each token in shorter name against best match in longer name
        $coreScores = [];
        foreach ($shorter as $tS) {
            $best = 0;
            foreach ($longer as $tL) {
                similar_text($tS, $tL, $pct);
                $best = max($best, (int) round($pct));
            }
            $coreScores[] = $best;
        }

        // Extra tokens in longer name — give partial credit at half weight
        $extraCount  = count($longer) - count($shorter);
        $extraScores = array_fill(0, max(0, $extraCount), 0); // extra tokens get no credit — unknown identity element

        $allScores = array_merge($coreScores, $extraScores);

        // Surname (last token of shorter name) gets double weight
        $weightedSum = 0;
        $totalWeight = 0;
        $lastIndex   = count($coreScores) - 1;

        foreach ($coreScores as $i => $s) {
            $weight      = ($i === $lastIndex) ? 2 : 1;
            $weightedSum += $s * $weight;
            $totalWeight += $weight;
        }

        // Add extra token scores at full weight — unmatched tokens are genuine unknowns
        foreach ($extraScores as $s) {
            $weightedSum += $s * 1.0;
            $totalWeight += 1.0;
        }

        $weighted = (int) round($weightedSum / $totalWeight);

        // Soundex bonus for transliteration variants
        $soundexBonus = $this->soundexBonus($normalizedA, $normalizedB);

        $final = min(100, $weighted + (int) round($soundexBonus * 0.5));

        // Apply FLAG cap for near-identical but distinct surnames with weak first name match
        if (!empty($capAt84)) {
            $final = min(84, $final);
        }

        // First name divergence penalty — applied directly to name score
        // If first tokens are very different (< 40%), this is likely a different person
        // sharing a surname (e.g. family member). Cap the score to prevent false blocks.
        // This fires AFTER the capAt84 logic to ensure it takes priority.
        $firstA = $tokensA[0] ?? '';
        $firstB = $tokensB[0] ?? '';
        similar_text($firstA, $firstB, $firstNamePct);
        if ($firstNamePct < 40.0 && $final > 70) {
            // Strong first name divergence — cap at 70 (below FLAG threshold of 60... wait FLAG=60)
            // Cap at 75 — keeps it in FLAG territory for human review, never auto-blocks
            $final = min(75, $final);
        }

        return $final;
    }

    /**
     * Score a name against a list entry's normalized name AND its aliases.
     * Returns the highest score found across name + all aliases.
     *
     * @param  string    $normalizedInput
     * @param  string    $entryNormalizedName
     * @param  string[]  $entryAliases  (already normalized)
     */
    public function scoreAgainstEntry(
        string $normalizedInput,
        string $entryNormalizedName,
        array  $entryAliases = []
    ): array {
        $best       = $this->score($normalizedInput, $entryNormalizedName);
        $aliasMatch = false;

        foreach ($entryAliases as $alias) {
            $s = $this->score($normalizedInput, $alias);
            if ($s > $best) {
                $best       = $s;
                $aliasMatch = true;
            }
        }

        return [
            'score'       => $best,
            'alias_match' => $aliasMatch,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Remove diacritics by converting to NFD and stripping combining marks.
     * Falls back to iconv transliteration if intl is not available.
     */
    private function removeDiacritics(string $str): string
    {
        if (function_exists('normalizer_normalize')) {
            // Decompose characters into base + combining mark, then strip marks
            $normalized = \Normalizer::normalize($str, \Normalizer::FORM_D);
            if ($normalized !== false) {
                return preg_replace('/\p{Mn}/u', '', $normalized);
            }
        }

        // Fallback: iconv transliteration
        $result = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        return $result !== false ? $result : $str;
    }

    /**
     * Calculate a soundex bonus (0–30) by comparing token soundex codes
     * across both names. Handles reordered name parts.
     */
    /**
     * Determine if two surname tokens are similar enough to proceed with scoring.
     * Uses levenshtein normalized by max length — more accurate than similar_text
     * for short strings where character overlap is misleading.
     *
     * "banda" vs "brand" → 2 edits / 5 chars = 60% similar → FALSE (not similar enough)
     * "johnson" vs "johnston" → 1 edit / 8 chars = 87% similar → TRUE
     * "mummert" vs "mummert" → 0 edits → TRUE
     */
    private function surnameSimilar(string $a, string $b): bool
    {
        if ($a === $b) return true;

        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) return false;

        $lev        = levenshtein($a, $b);
        $similarity = 1 - ($lev / $maxLen);

        // Require 75% similarity — 1 edit on 4+ char word passes, 2 edits on short word fails
        return $similarity >= 0.75;
    }

    private function soundexBonus(string $a, string $b): int
    {
        $tokensA = explode(' ', $a);
        $tokensB = explode(' ', $b);

        $codesA = array_map('soundex', $tokensA);
        $codesB = array_map('soundex', $tokensB);

        $matches    = count(array_intersect($codesA, $codesB));
        $totalPairs = max(count($codesA), count($codesB));

        if ($totalPairs === 0) {
            return 0;
        }

        return (int) round(($matches / $totalPairs) * 30);
    }
}
