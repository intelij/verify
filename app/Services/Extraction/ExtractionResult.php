<?php

namespace App\Services\Extraction;

/**
 * Normalized output of any extraction provider. Providers must map their
 * proprietary responses into this shape — the domain never sees vendor data.
 */
final class ExtractionResult
{
    /**
     * @param array<string,string> $fields            normalized identity fields
     * @param array<string,float>  $fieldConfidences  per-field confidence 0..1
     * @param array<string>        $signals           non-PII signals e.g. mrz_present
     */
    public function __construct(
        public readonly string $provider,
        public readonly ?string $providerVersion,
        public readonly ?string $documentType,     // DocumentType value or null
        public readonly ?string $country,          // ISO-3166 alpha-2/3 or null
        public readonly array $fields,
        public readonly array $fieldConfidences,
        public readonly float $extractionConfidence,
        public readonly array $signals = [],
        public readonly ?string $rawText = null,   // kept in memory only
        public readonly ?int $durationMs = null,
        public readonly ?int $pageCount = null,
    ) {}

    public function hasField(string $field): bool
    {
        return isset($this->fields[$field]) && $this->fields[$field] !== '';
    }

    public function field(string $field): ?string
    {
        return $this->fields[$field] ?? null;
    }

    /**
     * Result used when a provider cannot handle/failed on the document.
     */
    public static function failure(string $provider, string $errorCode): self
    {
        return new self(
            provider: $provider,
            providerVersion: null,
            documentType: null,
            country: null,
            fields: [],
            fieldConfidences: [],
            extractionConfidence: 0.0,
            signals: ['error:' . $errorCode],
        );
    }
}
