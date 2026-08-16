<?php

namespace App\Music\Assistant;

use RuntimeException;
use Throwable;

class CollectionAssistantProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, previous: $previous);
    }
}
