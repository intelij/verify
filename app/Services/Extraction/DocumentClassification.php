<?php

namespace App\Services\Extraction;

final class DocumentClassification
{
    /**
     * @param array<string> $signals non-PII evidence, e.g. 'mrz_present', 'keyword:passport'
     */
    public function __construct(
        public readonly ?string $documentType,
        public readonly ?string $country,
        public readonly float $confidence,
        public readonly array $signals = [],
    ) {}
}
