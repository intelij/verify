<?php

namespace App\Services\Extraction;

/**
 * Maps OCR text into normalized identity fields.
 *
 * Strategies (in priority order):
 *  - ICAO 9303 MRZ parsing (passports / TD1 cards) with check-digit validation
 *  - label-proximity regexes (SURNAME, DATE OF BIRTH, ID NO, ...)
 *  - document-number format matching (country rules)
 *
 * Every extracted field carries a confidence so the matcher can weigh it.
 */
class FieldExtractor
{
    public function extract(string $text, DocumentClassification $classification): array
    {
        $fields = [];
        $confs = [];
        $signals = [];

        // 1. MRZ — highest-fidelity source when present.
        if ($mrz = $this->parseMrz($text)) {
            $signals[] = 'mrz_parsed';
            foreach ($mrz['fields'] as $k => $v) {
                $fields[$k] = $v;
                $confs[$k] = 0.98;
            }
            if ($mrz['check_digit_errors'] !== []) {
                $signals[] = 'mrz_check_digit_mismatch';
            }
        }

        // 2. Label-proximity extraction for fields the MRZ didn't cover.
        foreach ($this->labelPatterns() as $field => [$pattern, $conf]) {
            if (isset($fields[$field])) {
                continue;
            }
            if (preg_match($pattern, $text, $m)) {
                $value = trim($m['v'] ?? $m[1]);
                if ($value !== '') {
                    $fields[$field] = $value;
                    $confs[$field] = $conf;
                }
            }
        }

        // 3. Dates — DOB & expiry from common formats.
        $this->extractDates($text, $fields, $confs);

        // 4. Document / ID numbers per country format.
        $this->extractDocumentNumber($text, $classification, $fields, $confs, $signals);

        // Normalize whitespace in all fields.
        foreach ($fields as $k => $v) {
            $fields[$k] = preg_replace('/\s+/', ' ', trim((string) $v));
        }

        return [
            'fields' => $fields,
            'field_confidences' => $confs,
            'signals' => $signals,
        ];
    }

    // ------------------------------------------------------------------
    // MRZ (ICAO 9303)
    // ------------------------------------------------------------------

    /**
     * Parse TD3 (passport, 2x44) MRZ. Returns null when no valid MRZ.
     * @return array{fields: array, check_digit_errors: string[]}|null
     */
    private function parseMrz(string $text): ?array
    {
        // Collect candidate MRZ lines (>=28 chars of A-Z0-9<).
        $candidates = [];
        foreach (preg_split('/[\r\n]+/', $text) as $line) {
            $line = strtoupper(preg_replace('/[^A-Z0-9<]/', '', str_replace(' ', '', $line)));
            if (strlen($line) >= 28) {
                $candidates[] = $line;
            }
        }
        if (count($candidates) < 2) {
            return null;
        }

        // TD3: lines of exactly 44 chars (allow OCR to give 40-44).
        $l1 = $l2 = null;
        foreach ($candidates as $c) {
            if ($l1 === null && strlen($c) >= 40 && str_starts_with($c, 'P')) {
                $l1 = substr($c, 0, 44);
                continue;
            }
            if ($l1 !== null && $l2 === null && strlen($c) >= 40) {
                $l2 = substr($c, 0, 44);
                break;
            }
        }
        if ($l1 === null || $l2 === null) {
            return null;
        }

        $errors = [];
        $fields = [];

        // Line 1: P<ISSUING<<SURNAME<<GIVEN<NAMES
        if (preg_match('/^P<([A-Z<]{3})([A-Z<]+)$/', $l1, $m)) {
            $namePart = $m[2];
            $segments = array_values(array_filter(explode('<<', $namePart), fn ($s) => $s !== ''));
            $surname = isset($segments[0]) ? str_replace('<', ' ', trim($segments[0])) : null;
            $given = isset($segments[1]) ? str_replace('<', ' ', trim($segments[1])) : null;
            if ($surname) {
                $fields['surname'] = $surname;
            }
            if ($given) {
                $fields['first_name'] = $given;
            }
        }

        // Line 2: docNumber(9) cd nationality(3) dob(6) cd sex(1) expiry(6) cd personal...
        $l2p = str_pad($l2, 44, '<');
        $docNumber = rtrim(substr($l2p, 0, 9), '<');
        $nationality = substr($l2p, 10, 3);
        $dob = substr($l2p, 13, 6);
        $expiry = substr($l2p, 21, 6);
        $sex = substr($l2p, 20, 1);

        if (! $this->mrzCheckDigit(substr($l2p, 0, 9), $l2p[9] ?? '')) {
            $errors[] = 'document_number';
        }
        if (! $this->mrzCheckDigit($dob, $l2p[19] ?? '')) {
            $errors[] = 'date_of_birth';
        }
        if (! $this->mrzCheckDigit($expiry, $l2p[27] ?? '')) {
            $errors[] = 'expiry_date';
        }

        if ($docNumber !== '') {
            $fields['document_number'] = $docNumber;
            $fields['passport_number'] = $docNumber;
        }
        if (preg_match('/^\d{6}$/', $dob)) {
            $fields['date_of_birth'] = $this->mrzDateToIso($dob, true);
        }
        if (preg_match('/^\d{6}$/', $expiry)) {
            $fields['expiry_date'] = $this->mrzDateToIso($expiry, false);
        }
        if (in_array($sex, ['M', 'F'], true)) {
            $fields['gender'] = $sex === 'M' ? 'male' : 'female';
        }
        if (strlen($nationality) === 3 && $nationality !== '<<<') {
            $map = ['ZWE' => 'ZW', 'ZAF' => 'ZA', 'ZMB' => 'ZM', 'KEN' => 'KE'];
            $fields['nationality'] = $map[$nationality] ?? $nationality;
        }

        return ['fields' => $fields, 'check_digit_errors' => $errors];
    }

    private function mrzCheckDigit(string $data, string $check): bool
    {
        if ($check === '<' || $check === '') {
            return true; // no check digit present
        }
        $weights = [7, 3, 1];
        $sum = 0;
        foreach (str_split($data) as $i => $ch) {
            $v = ctype_digit($ch) ? (int) $ch
                : ($ch === '<' ? 0 : ord($ch) - 55);
            $sum += $v * $weights[$i % 3];
        }
        return ($sum % 10) === (int) $check;
    }

    private function mrzDateToIso(string $yymmdd, bool $isDob): string
    {
        $yy = (int) substr($yymmdd, 0, 2);
        // MRZ pivot: DOB years map to past century window; expiry to future.
        $year = $isDob
            ? ($yy <= (int) date('y') ? 2000 + $yy : 1900 + $yy)
            : ($yy < 70 ? 2000 + $yy : 1900 + $yy);

        return sprintf('%04d-%s-%s', $year, substr($yymmdd, 2, 2), substr($yymmdd, 4, 2));
    }

    // ------------------------------------------------------------------
    // Label-proximity patterns
    // ------------------------------------------------------------------

    /** @return array<string, array{0:string,1:float}> */
    private function labelPatterns(): array
    {
        return [
            'surname' => ['/\b(?:surname|last\s*name|family\s*name|apelido)\b[\s:.\-]*(?P<v>[A-Za-z\'\- ]{2,40})/i', 0.75],
            'first_name' => ['/\b(?:given\s*names?|first\s*names?|forenames?|christian\s*names?)\b[\s:.\-]*(?P<v>[A-Za-z\'\- ]{2,60})/i', 0.75],
            'full_name' => ['/\b(?:full\s*name|name\s*of\s*holder|holder\'?s\s*name)\b[\s:.\-]*(?P<v>[A-Za-z\'\- ]{4,80})/i', 0.6],
            'license_number' => ['/\b(?:licen[cs]e|permit)\s*(?:no|number|#)\.?[\s:.\-]*(?P<v>[A-Z0-9\-\/]{4,20})/i', 0.8],
            'document_number' => ['/\b(?:id(?:entity)?|registration|document|card)\s*(?:no|number|#)\.?[\s:.\-]*(?P<v>[A-Z0-9\-\/ ]{5,25})/i', 0.75],
            'gender' => ['/\b(?:sex|gender)\b[\s:.\-]*(?P<v>male|female|m|f)\b/i', 0.7],
        ];
    }

    private function extractDates(string $text, array &$fields, array &$confs): void
    {
        $datePatterns = [
            // 1990-04-15 / 15-04-1990 / 15 Apr 1990 / April 15, 1990 / 15/04/1990
            '/(?P<y>\d{4})[-\/.](?P<m>\d{1,2})[-\/.](?P<d>\d{1,2})/',
            '/(?P<d>\d{1,2})[-\/.](?P<m>\d{1,2})[-\/.](?P<y>\d{4})/',
            '/(?P<d>\d{1,2})\s+(?P<mn>Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s*,?\s+(?P<y>\d{4})/i',
            '/(?P<mn>Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+(?P<d>\d{1,2})\s*,?\s+(?P<y>\d{4})/i',
        ];

        $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];

        $findDates = function (string $haystack) use ($datePatterns, $months): array {
            $found = [];
            foreach ($datePatterns as $p) {
                if (preg_match_all($p, $haystack, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    foreach ($mm as $hit) {
                        $d = (int) $hit['d'][0];
                        $m = isset($hit['mn']) ? $months[strtolower(substr($hit['mn'][0], 0, 3))] ?? 0 : (int) $hit['m'][0];
                        $y = (int) $hit['y'][0];
                        if (checkdate($m, $d, $y)) {
                            $found[] = ['date' => sprintf('%04d-%02d-%02d', $y, $m, $d), 'pos' => $hit[0][1]];
                        }
                    }
                }
            }
            return $found;
        };

        // Contextual windows around DOB / expiry labels.
        foreach ([
            'date_of_birth' => '/(?:date\s*of\s*birth|birth\s*date|born|d\.?o\.?b)\b.{0,40}/is',
            'expiry_date' => '/(?:expir(?:y|es|ation)|valid\s*until|date\s*of\s*expiry)\b.{0,40}/is',
            'issue_date' => '/(?:date\s*of\s*issue|issued|issue\s*date)\b.{0,40}/is',
        ] as $field => $ctx) {
            if (isset($fields[$field])) {
                continue;
            }
            if (preg_match($ctx, $text, $m)) {
                $dates = $findDates($m[0]);
                if ($dates !== []) {
                    $fields[$field] = $dates[0]['date'];
                    $confs[$field] = 0.8;
                }
            }
        }

        // Fallback DOB: any plausible birth date (18–100 yrs ago) not yet used.
        if (! isset($fields['date_of_birth'])) {
            foreach ($findDates($text) as $f) {
                $age = (int) ((time() - strtotime($f['date'])) / 31557600);
                if ($age >= 16 && $age <= 100) {
                    $fields['date_of_birth'] = $f['date'];
                    $confs['date_of_birth'] = 0.5;
                    break;
                }
            }
        }
    }

    private function extractDocumentNumber(string $text, DocumentClassification $c, array &$fields, array &$confs, array &$signals): void
    {
        $country = $c->country;
        $rules = $country ? (config('verification.countries.' . $country) ?? []) : [];
        $idRule = $rules['national_id']['number_format'] ?? null;

        if ($idRule && preg_match($idRule, str_replace(' ', '', $text) . ' ', $m)) {
            // Search token-by-token rather than the whole blob.
        }
        if ($idRule) {
            foreach (preg_split('/\s+/', $text) as $token) {
                $token = trim($token);
                if ($token !== '' && preg_match($idRule, $token)) {
                    $fields['document_number'] ??= $token;
                    $confs['document_number'] = 0.9;
                    $signals[] = 'id_format:' . $country;
                    return;
                }
            }
        }
    }
}
