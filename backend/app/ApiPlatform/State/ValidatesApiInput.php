<?php

namespace App\ApiPlatform\State;

use ApiPlatform\Laravel\ApiResource\ValidationError;
use App\Music\Scanning\InvalidLibraryPath;

trait ValidatesApiInput
{
    private function validated(string $field, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (InvalidLibraryPath $exception) {
            $this->invalid($field, $exception->getMessage(), $exception);
        }
    }

    private function invalid(string $field, string $message, ?\Throwable $previous = null): never
    {
        throw new ValidationError(
            $message,
            hash('xxh3', $field),
            $previous,
            [['propertyPath' => $field, 'message' => $message]],
        );
    }
}
