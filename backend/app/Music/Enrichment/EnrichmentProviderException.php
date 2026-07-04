<?php

namespace App\Music\Enrichment;

use RuntimeException;

class EnrichmentProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retriable = true,
        public readonly string $errorCode = 'provider_error',
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
