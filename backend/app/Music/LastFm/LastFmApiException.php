<?php

namespace App\Music\LastFm;

use RuntimeException;

class LastFmApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $apiCode = null,
        public readonly bool $retriable = false,
    ) {
        parent::__construct($message);
    }
}
