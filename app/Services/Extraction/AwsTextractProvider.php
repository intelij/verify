<?php

namespace App\Services\Extraction;

use App\Services\Extraction\Contracts\ExtractionProvider;
use App\Services\Extraction\Contracts\ProviderUnavailableException;

/**
 * AWS Textract adapter (AnalyzeID / DetectDocumentText).
 *
 * Implemented behind the same ExtractionProvider contract so the domain
 * never depends on the vendor. It is enabled only when credentials are
 * configured (TEXTRACT_ENABLED + AWS_*); otherwise it reports itself
 * unavailable and the provider chain skips it — documents are never sent
 * to a third party silently.
 */
class AwsTextractProvider implements ExtractionProvider
{
    public function name(): string
    {
        return 'aws_textract';
    }

    public function isAvailable(): bool
    {
        $c = config('verification.providers.aws_textract');
        return ($c['enabled'] ?? false)
            && ! empty($c['key']) && ! empty($c['secret']) && ! empty($c['region']);
    }

    public function supports(string $mimeType, string $absolutePath): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/png', 'application/pdf'], true);
    }

    public function extract(string $absolutePath, string $mimeType): ExtractionResult
    {
        // Textract integration requires the AWS SDK + a signed data-processing
        // agreement. Until it is configured and verified, this provider is
        // honestly unavailable rather than fabricating output.
        throw new ProviderUnavailableException(
            $this->name(),
            'not_configured',
            'AWS Textract provider not configured for this deployment',
        );
    }
}
