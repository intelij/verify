<?php

namespace App\Services\Extraction\Contracts;

use App\Services\Extraction\ExtractionResult;

/**
 * Abstraction over OCR / document-intelligence vendors.
 * Implementations: TesseractOcrProvider (local), AwsTextractProvider, ...
 */
interface ExtractionProvider
{
    public function name(): string;

    /**
     * Whether this provider is configured and able to run in this environment.
     */
    public function isAvailable(): bool;

    /**
     * Whether the provider supports this file (mime + path).
     */
    public function supports(string $mimeType, string $absolutePath): bool;

    /**
     * Extract structured data from a document stored on the local filesystem.
     * Must not throw on OCR failure — return ExtractionResult::failure() or a
     * low-confidence result; throw ProviderUnavailableException only when the
     * provider itself cannot be used at all.
     */
    public function extract(string $absolutePath, string $mimeType): ExtractionResult;
}
