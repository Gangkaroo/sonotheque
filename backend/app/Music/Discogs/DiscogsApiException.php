<?php

namespace App\Music\Discogs;

use RuntimeException;

class DiscogsApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retriable = false,
    ) {
        parent::__construct($message);
    }
}
