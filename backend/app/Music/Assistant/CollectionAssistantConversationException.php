<?php

namespace App\Music\Assistant;

use RuntimeException;

class CollectionAssistantConversationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
