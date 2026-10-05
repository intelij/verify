<?php

namespace App\Services\Matching;

use App\Enums\ReasonCode;
use App\Support\PiiMasker;

/**
 * Compares extracted document identity against the subject identity supplied
 * by the consumer. Fields are evaluated independently; ambiguous similarity
 * produces a review signal — never an automatic pass.
 */
class IdentityMatcher
{
    public function __construct(private readonly IdentityNormalizer $normalizer) {}

    /**
     * @param array $expected subject fields from VerificationSubject::expectedIdentity()
     * @param array $extracted fields from ExtractionResult::fields
     * @param array $fieldConfs extraction-side confidences
     * @return array{fields: FieldMatch[], overall_score: float, reasons: string[], ambiguous: bool}
     */
    public function compare(array $expected, array $extracted, array $fieldConfs = []): array
    {
        $results = [];
        $reasons = [];
        $ambiguous = false;

        // --- first_name ---
        $results['first_name'] = $this->matchName(
            'first_name',
            $expected['first_name'] ?? null,
            $extracted['first_name'] ?? null,
            $fieldConfs['first_name'] ?? 0.6,
            $extracted,
            $reasons,
            $ambiguous,
        );

        // --- surname ---
        $results['surname'] = $this->matchName(
            'surname',
            $expected['surname'] ?? null,
            $extracted['surname'] ?? null,
            $fieldConfs['surname'] ?? 0.6,
            $extracted,
            $reasons,
            $ambiguous,
        );

        // --- middle_names (optional; initial tolerance) ---
        $results['middle_names'] = $this->matchMiddleNames(
            $expected['middle_names'] ?? null,
            $extracted,
            $reasons,
        );

        // --- date_of_birth ---
        $results['date_of_birth'] = $this->matchDate(
            $expected['date_of_birth'] ?? null,
            $extracted['date_of_birth'] ?? null,
            $fieldConfs['date_of_birth'] ?? 0.7,
            $reasons,
        );

        // --- document numbers ---
        foreach ([
            'national_id_number' => 'document_number',
            'passport_number' => 'passport_number',
            'driver_license_number' => 'license_number',
        ] as $expectedKey => $extractKey) {
            if (! isset($expected[$expectedKey])) {
                continue;
            }
            $results[$expectedKey] = $this->matchIdentifier(
                $expectedKey,
                $expected[$expectedKey],
                $extracted[$extractKey] ?? $extracted['document_number'] ?? null,
                $reasons,
            );
        }

        // --- gender ---
        if (isset($expected['gender']) && isset($extracted['gender'])) {
            $e = strtolower(substr($expected['gender'], 0, 1));
            $x = strtolower(substr($extracted['gender'], 0, 1));
            $results['gender'] = new FieldMatch(
                'gender', $e === $x, 1.0,
                $e === $x ? 'GENDER_MATCH' : 'GENDER_MISMATCH',
                PiiMasker::mask($expected['gender']),
            );
        }

        // Overall score: weighted mean over comparable fields.
        $weights = [
            'first_name' => 0.25, 'surname' => 0.30, 'middle_names' => 0.05,
            'date_of_birth' => 0.20, 'national_id_number' => 0.20,
            'passport_number' => 0.20, 'driver_license_number' => 0.15,
            'gender' => 0.05,
        ];
        $sum = $wsum = 0.0;
        foreach ($results as $field => $r) {
            if ($r->match === null) {
                continue;
            }
            $w = $weights[$field] ?? 0.05;
            $sum += ($r->match ? $r->confidence : $r->confidence * 0.2) * $w;
            $wsum += $w;
        }
        $overall = $wsum > 0 ? round($sum / $wsum, 4) : 0.0;

        return [
            'fields' => $results,
            'overall_score' => $overall,
            'reasons' => array_values(array_unique($reasons)),
            'ambiguous' => $ambiguous,
        ];
    }

    private function matchName(
        string $field, ?string $expected, ?string $extracted,
        float $extractionConf, array $extractedFields, array &$reasons, bool &$ambiguous,
    ): FieldMatch {
        $expectedMasked = PiiMasker::maskName($expected);

        if ($expected === null || $expected === '') {
            return new FieldMatch($field, null, 0, ReasonCode::FIELD_NOT_PROVIDED, $expectedMasked, false);
        }

        // Fallback: derive from full_name when specific field absent.
        if (($extracted === null || $extracted === '') && isset($extractedFields['full_name'])) {
            $tokens = $this->normalizer->nameTokens($extractedFields['full_name']);
            $extracted = $field === 'surname' ? end($tokens) : ($tokens[0] ?? null);
        }
        if (($extracted === null || $extracted === '') && isset($extractedFields[$field])) {
            $extracted = $extractedFields[$field];
        }

        if ($extracted === null || $extracted === '') {
            $reasons[] = ReasonCode::FIELD_NOT_EXTRACTED;
            $ambiguous = true;
            return new FieldMatch($field, null, 0, ReasonCode::FIELD_NOT_EXTRACTED, $expectedMasked);
        }

        $e = $this->normalizer->name($expected);
        $x = $this->normalizer->name($extracted);

        if ($e === $x) {
            $reasons[] = $field === 'first_name' ? ReasonCode::FIRST_NAME_MATCH : ReasonCode::SURNAME_MATCH;
            return new FieldMatch($field, true, round($extractionConf, 3),
                $field === 'first_name' ? ReasonCode::FIRST_NAME_MATCH : ReasonCode::SURNAME_MATCH,
                $expectedMasked);
        }

        // Token-level check: extracted may contain extra middle tokens.
        // "john tendai" vs "john" -> first-name subset is acceptable only when
        // the FIRST token matches for first_name / LAST token for surname.
        $eTokens = explode(' ', $e);
        $xTokens = explode(' ', $x);
        $posMatch = $field === 'first_name'
            ? ($eTokens[0] === $xTokens[0])
            : (end($eTokens) === end($xTokens));

        if ($posMatch && count($xTokens) >= count($eTokens)) {
            $reasons[] = $field === 'first_name' ? ReasonCode::FIRST_NAME_MATCH : ReasonCode::SURNAME_MATCH;
            return new FieldMatch($field, true, round(min($extractionConf, 0.9), 3),
                $field === 'first_name' ? ReasonCode::FIRST_NAME_MATCH : ReasonCode::SURNAME_MATCH,
                $expectedMasked);
        }

        // Similarity for near-matches (OCR noise).
        similar_text($e, $x, $pct);
        $sim = $pct / 100;
        $threshold = (float) config('verification.matching.name_similarity_threshold', 0.92);
        $reviewBelow = (float) config('verification.matching.name_review_threshold', 0.72);

        if ($sim >= $threshold) {
            $reasons[] = $field === 'first_name' ? ReasonCode::FIRST_NAME_MATCH : ReasonCode::SURNAME_MATCH;
            return new FieldMatch($field, true, round($sim * $extractionConf, 3),
                $field === 'first_name' ? ReasonCode::FIRST_NAME_MATCH : ReasonCode::SURNAME_MATCH,
                $expectedMasked);
        }

        if ($sim >= $reviewBelow) {
            // Ambiguous: close but not conclusive — flag for review.
            $ambiguous = true;
            $reasons[] = ReasonCode::MANUAL_REVIEW_REQUIRED;
            return new FieldMatch($field, null, round($sim * $extractionConf, 3),
                ReasonCode::MANUAL_REVIEW_REQUIRED, $expectedMasked);
        }

        $reasons[] = $field === 'first_name' ? ReasonCode::FIRST_NAME_MISMATCH : ReasonCode::SURNAME_MISMATCH;
        return new FieldMatch($field, false, round($extractionConf, 3),
            $field === 'first_name' ? ReasonCode::FIRST_NAME_MISMATCH : ReasonCode::SURNAME_MISMATCH,
            $expectedMasked);
    }

    private function matchMiddleNames(?string $expected, array $extracted, array &$reasons): FieldMatch
    {
        if ($expected === null || $expected === '') {
            return new FieldMatch('middle_names', null, 0, ReasonCode::FIELD_NOT_PROVIDED, null, false);
        }

        // Middle names rarely appear as a dedicated field; compare against
        // the extracted first_name token stream.
        $eTokens = $this->normalizer->nameTokens($expected);
        $xTokens = $this->normalizer->nameTokens($extracted['first_name'] ?? null);

        if ($xTokens === []) {
            return new FieldMatch('middle_names', null, 0, ReasonCode::FIELD_NOT_EXTRACTED, PiiMasker::maskName($expected));
        }

        // Initial tolerance: "tendai" expected vs "t" extracted is a match;
        // "t" expected vs "tendai" extracted is a match.
        foreach ($eTokens as $et) {
            $hit = false;
            foreach ($xTokens as $xt) {
                if ($et === $xt || (mb_strlen($et) === 1 && str_starts_with($xt, $et))
                    || (mb_strlen($xt) === 1 && str_starts_with($et, $xt))) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                $reasons[] = ReasonCode::MANUAL_REVIEW_REQUIRED;
                return new FieldMatch('middle_names', null, 0.5, ReasonCode::MANUAL_REVIEW_REQUIRED, PiiMasker::maskName($expected));
            }
        }

        $reasons[] = ReasonCode::MIDDLE_NAMES_MATCH;
        return new FieldMatch('middle_names', true, 0.9, ReasonCode::MIDDLE_NAMES_MATCH, PiiMasker::maskName($expected));
    }

    private function matchDate(?string $expected, ?string $extracted, float $extractionConf, array &$reasons): FieldMatch
    {
        $masked = $expected ? '****-**-' . substr($expected, -2) : null;
        if ($expected === null) {
            return new FieldMatch('date_of_birth', null, 0, ReasonCode::FIELD_NOT_PROVIDED, $masked, false);
        }
        if ($extracted === null || $extracted === '') {
            $reasons[] = ReasonCode::FIELD_NOT_EXTRACTED;
            return new FieldMatch('date_of_birth', null, 0, ReasonCode::FIELD_NOT_EXTRACTED, $masked);
        }

        $e = $this->normalizer->date($expected);
        $x = $this->normalizer->date($extracted);
        $match = $e !== null && $e === $x;
        $reasons[] = $match ? ReasonCode::DATE_OF_BIRTH_MATCH : ReasonCode::DOB_MISMATCH;

        return new FieldMatch('date_of_birth', $match, round($extractionConf, 3),
            $match ? ReasonCode::DATE_OF_BIRTH_MATCH : ReasonCode::DOB_MISMATCH, $masked);
    }

    private function matchIdentifier(string $field, string $expected, ?string $extracted, array &$reasons): FieldMatch
    {
        $masked = PiiMasker::mask($expected);
        if ($extracted === null || $extracted === '') {
            $reasons[] = ReasonCode::FIELD_NOT_EXTRACTED;
            return new FieldMatch($field, null, 0, ReasonCode::FIELD_NOT_EXTRACTED, $masked);
        }
        $e = $this->normalizer->identifier($expected);
        $x = $this->normalizer->identifier($extracted);
        $match = $e !== null && $e === $x;
        $reasons[] = $match ? ReasonCode::DOCUMENT_NUMBER_MATCH : ReasonCode::DOCUMENT_NUMBER_MISMATCH;

        return new FieldMatch($field, $match, 1.0,
            $match ? ReasonCode::DOCUMENT_NUMBER_MATCH : ReasonCode::DOCUMENT_NUMBER_MISMATCH, $masked);
    }
}
