<?php

namespace App\Music\Scanning;

class RawMetadataSanitizer
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sanitize(array $metadata): array
    {
        return json_decode(
            json_encode($this->sanitizeValue($metadata), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $child) {
                $sanitized[$this->sanitizeKey($key)] = $this->sanitizeValue($child);
            }

            return $sanitized;
        }

        if (is_string($value) && str_contains($value, "\0")) {
            return sprintf('[binary data omitted: %d bytes]', strlen($value));
        }

        if (is_float($value) && ! is_finite($value)) {
            return '[non-finite number omitted]';
        }

        return $value;
    }

    private function sanitizeKey(int|string $key): int|string
    {
        if (is_int($key)) {
            return $key;
        }

        if (str_contains($key, "\0")) {
            return sprintf('[binary key omitted: %d bytes]', strlen($key));
        }

        return $key;
    }
}
