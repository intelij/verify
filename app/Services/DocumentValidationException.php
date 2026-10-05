<?php

namespace App\Services;

use RuntimeException;

class DocumentValidationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
