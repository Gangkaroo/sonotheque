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
            foreach ($value as $key => $child) {
                $value[$key] = $this->sanitizeValue($child);
            }

            return $value;
        }

        if (is_string($value) && str_contains($value, "\0")) {
            return sprintf('[binary data omitted: %d bytes]', strlen($value));
        }

        return $value;
    }
}
