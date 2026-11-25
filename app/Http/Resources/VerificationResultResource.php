<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rich verification result — never exposes raw OCR or unmasked identifiers.
 */
class VerificationResultResource extends JsonResource
{
    public function toArray($request): array
    {
        $fields = $this->fieldResults->mapWithKeys(fn ($r) => [$r->field => [
            'match' => $r->match,
            'confidence' => $r->confidence,
            'reason' => $r->reason_code,
            'provided' => $r->provided,
        ]]);

        $extraction = $this->documents->flatMap->extractions->first();

        return [
            'verification_id' => $this->public_id,
            'external_reference' => $this->external_reference,
            'status' => $this->status,
            'result' => [
                'overall_confidence' => $this->confidence,
                'document_type' => $extraction?->document_type,
                'document_country' => $extraction?->country,
                'identity_match' => $this->computeIdentityMatch(),
                'fields' => $fields,
                'extracted' => $this->extractedSummary($extraction),
                'document_expiry' => $extraction?->fields['expiry_date'] ?? null,
                'reasons' => $this->reason_codes ?? [],
                'authenticity_signals' => $extraction?->signals ?? [],
            ],
            'policy' => $this->policy_code,
            'provider' => $extraction?->provider,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'verified_until' => $this->verified_until?->toIso8601String(),
        ];
    }

    /**
     * Extracted field values for the consuming backend — it submitted the
     * document and owns the subject record. Identifier values are masked to
     * the last 4 chars; names/dates are returned in full so the consumer can
     * store the extraction summary. Raw OCR text is never included.
     */
    private function extractedSummary($extraction): ?array
    {
        if (! $extraction || ! is_array($extraction->fields)) {
            return null;
        }
        $identifierFields = ['document_number', 'national_id_number',
            'passport_number', 'driver_license_number', 'license_number'];

        $out = [];
        foreach ($extraction->fields as $field => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            $out[$field] = in_array($field, $identifierFields, true)
                ? \App\Support\PiiMasker::mask($value)
                : $value;
        }

        return $out === [] ? null : $out;
    }

    private function computeIdentityMatch(): ?bool
    {
        $comparable = $this->fieldResults->filter(fn ($r) => $r->match !== null);
        if ($comparable->isEmpty()) {
            return null;
        }
        return $comparable->every(fn ($r) => $r->match === true);
    }
}
