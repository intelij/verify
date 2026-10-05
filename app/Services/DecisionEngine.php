<?php

namespace App\Services;

use App\Enums\ReasonCode;
use App\Enums\VerificationStatus;
use App\Models\VerificationPolicy;

/**
 * Fuses the pipeline signals into a single decision:
 *   quality + doc-type + extraction + identity match + validity + authenticity
 *
 * Rules:
 *  - any required-field hard mismatch -> FAILED
 *  - low quality / unreadable         -> FAILED (actionable reason)
 *  - ambiguous match band             -> REVIEW_REQUIRED
 *  - all checks pass                  -> VERIFIED (or PARTIALLY_VERIFIED
 *    when optional fields failed)
 */
final class DecisionEngine
{
    /**
     * @param array $matchResult  IdentityMatcher::compare() output
     * @param array $extractions  ExtractionResult[] per document
     * @param array $docQualities per-document quality payloads
     */
    public function decide(
        VerificationPolicy $policy,
        ?string $expectedDocType,
        array $matchResult,
        array $extractions,
        array $docQualities,
    ): array {
        $reasons = $matchResult['reasons'];
        $hardFail = [];
        $review = [];
        $optionalMiss = false;

        // ---- Document type ----
        $detectedTypes = array_values(array_filter(array_map(
            fn ($e) => $e->documentType, $extractions)));
        $typeAllowed = null;
        if ($detectedTypes !== []) {
            $typeAllowed = collect($detectedTypes)->intersect($policy->allowed_document_types)->isNotEmpty();
            if (! $typeAllowed) {
                $hardFail[] = in_array('unknown', $detectedTypes, true)
                    ? ReasonCode::DOCUMENT_TYPE_UNKNOWN
                    : ReasonCode::DOCUMENT_TYPE_NOT_ALLOWED;
            }
        }
        if ($expectedDocType && $detectedTypes !== [] && ! in_array($expectedDocType, $detectedTypes, true)) {
            $hardFail[] = ReasonCode::DOCUMENT_TYPE_MISMATCH;
        }

        // ---- Quality ----
        $worstQuality = 1.0;
        $qualityProblems = [];
        foreach ($docQualities as $q) {
            $worstQuality = min($worstQuality, (float) ($q['score'] ?? 0));
            foreach (($q['problems'] ?? []) as $p) {
                $qualityProblems[] = $p;
            }
        }
        if ($docQualities !== [] && $worstQuality < $policy->min_quality) {
            $hardFail[] = ReasonCode::DOCUMENT_UNREADABLE;
            $reasons[] = ReasonCode::REUPLOAD_REQUIRED;
        }

        // ---- Extraction confidence ----
        $bestExtraction = 0.0;
        foreach ($extractions as $e) {
            $bestExtraction = max($bestExtraction, $e->extractionConfidence);
        }
        if ($extractions !== [] && $bestExtraction < $policy->min_extraction_confidence) {
            $review[] = ReasonCode::LOW_EXTRACTION_CONFIDENCE;
        }

        // ---- Required field results ----
        foreach ($policy->required_fields as $field) {
            $r = $matchResult['fields'][$field] ?? null;
            if ($r === null || $r->match === null) {
                // Required field not comparable -> review, not silent fail.
                $review[] = $field . ':' . ReasonCode::FIELD_NOT_EXTRACTED;
                continue;
            }
            if ($r->match === false) {
                $hardFail[] = $r->reasonCode;
            }
        }
        foreach (($policy->optional_fields ?? []) as $field) {
            $r = $matchResult['fields'][$field] ?? null;
            if ($r !== null && $r->match === false) {
                $optionalMiss = true;
            }
        }

        // ---- Ambiguous band ----
        if ($matchResult['ambiguous']) {
            $review[] = ReasonCode::MANUAL_REVIEW_REQUIRED;
        }

        // ---- Document number validity (country rule) ----
        if ($policy->require_document_number) {
            $hasNumber = collect($extractions)->contains(
                fn ($e) => $e->hasField('document_number') || $e->hasField('license_number') || $e->hasField('passport_number'));
            if (! $hasNumber) {
                $review[] = ReasonCode::FIELD_NOT_EXTRACTED . ':document_number';
            }
        }

        // ---- Expiry ----
        if ($policy->require_not_expired) {
            foreach ($extractions as $e) {
                $exp = $e->field('expiry_date');
                if ($exp && strtotime($exp) !== false && strtotime($exp) < time()) {
                    $hardFail[] = ReasonCode::DOCUMENT_EXPIRED;
                }
            }
        }

        // ---- Authenticity signals ----
        $authenticitySignals = [];
        foreach ($extractions as $e) {
            if (in_array('mrz_check_digit_mismatch', $e->signals, true)) {
                $authenticitySignals[] = 'mrz_check_digit_mismatch';
            }
        }
        if ($authenticitySignals !== []) {
            if ($policy->require_authenticity_pass) {
                $hardFail[] = ReasonCode::DOCUMENT_AUTHENTICITY_FAILED;
            } else {
                $review[] = ReasonCode::RISK_FLAG;
            }
            $reasons[] = ReasonCode::RISK_FLAG;
        }

        // ---- Fuse ----
        if ($matchResult['overall_score'] < $policy->verify_threshold
            && $matchResult['overall_score'] >= $policy->review_threshold
            && $hardFail === []) {
            $review[] = ReasonCode::MANUAL_REVIEW_REQUIRED;
        }

        $reasons = array_values(array_unique(array_merge($reasons, $hardFail, $review)));
        $status = match (true) {
            $hardFail !== [] => VerificationStatus::Failed,
            $matchResult['overall_score'] < $policy->review_threshold => VerificationStatus::Failed,
            $review !== [] => VerificationStatus::ReviewRequired,
            $matchResult['overall_score'] >= $policy->verify_threshold =>
                $optionalMiss ? VerificationStatus::PartiallyVerified : VerificationStatus::Verified,
            default => VerificationStatus::Failed,
        };

        return [
            'status' => $status,
            'reasons' => $reasons,
            'confidence' => $matchResult['overall_score'],
            'quality_problems' => $qualityProblems,
        ];
    }
}
