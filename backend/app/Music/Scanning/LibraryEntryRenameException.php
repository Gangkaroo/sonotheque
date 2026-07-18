<?php

namespace App\Music\Scanning;

use RuntimeException;
use Throwable;

class LibraryEntryRenameException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
