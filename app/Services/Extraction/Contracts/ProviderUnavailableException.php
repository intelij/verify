<?php

namespace App\Services\Extraction\Contracts;

use RuntimeException;

class ProviderUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $providerName,
        public readonly string $errorCode,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Provider {$providerName} unavailable: {$errorCode}");
    }
}
