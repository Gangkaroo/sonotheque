<?php

namespace App\Music\Scanning;

use RuntimeException;

class ScanDispatchException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $field = 'libraryRootId',
    ) {
        parent::__construct($message);
    }
}
