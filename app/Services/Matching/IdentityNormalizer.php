<?php

namespace App\Services\Matching;

/**
 * Normalizes identity strings before comparison:
 *  - Unicode NFKD + diacritic strip (Ncube == Ncubé)
 *  - case fold
 *  - punctuation collapse ("O'Brien" -> "obrien")
 *  - whitespace collapse
 *  - "Surname, First" reordering
 *  - ISO date normalization
 */
class IdentityNormalizer
{
    public function name(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = \Normalizer::normalize($value, \Normalizer::FORM_KD) ?: $value;
        $v = preg_replace('/\p{Mn}+/u', '', $v);          // strip diacritics
        $v = mb_strtolower($v);
        $v = str_replace(["'", "\u{2019}", "\u{02BC}"], '', $v); // O'Brien -> obrien
        $v = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $v); // punctuation -> space
        $v = preg_replace('/\s+/u', ' ', trim($v));

        return $v !== '' ? $v : null;
    }

    /**
     * Tokens of a normalized name, middle initials expanded only when they
     * are true initials ("t" not "tendai").
     * @return string[]
     */
    public function nameTokens(?string $value): array
    {
        $v = $this->name($value);
        return $v === null ? [] : explode(' ', $v);
    }

    /**
     * "Moyo, John" -> "john moyo". Detects a leading surname when exactly two
     * comma-separated segments exist.
     */
    public function reorderCommaName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (str_contains($value, ',')) {
            $parts = array_map('trim', explode(',', $value, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $value = $parts[1] . ' ' . $parts[0];
            }
        }
        return $this->name($value);
    }

    public function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }
        $ts = strtotime($v);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    public function identifier(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = strtoupper(preg_replace('/[\s\-\.]/', '', trim($value)));
        return $v !== '' ? $v : null;
    }
}
