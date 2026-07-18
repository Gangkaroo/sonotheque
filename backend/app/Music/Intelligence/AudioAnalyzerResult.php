<?php

namespace App\Music\Intelligence;

use InvalidArgumentException;

final readonly class AudioAnalyzerResult
{
    /**
     * @param  array<string, mixed>  $features
     * @param  list<float>  $embedding
     * @param  array<string, int>  $timings
     * @param  array<string, mixed>  $hardware
     */
    public function __construct(
        public int $itemId,
        public string $status,
        public array $features = [],
        public array $embedding = [],
        public ?int $runtimeMs = null,
        public ?int $windowsAnalyzed = null,
        public array $timings = [],
        public array $hardware = [],
        public ?string $error = null,
    ) {
        if (! in_array($this->status, ['completed', 'failed'], true)) {
            throw new InvalidArgumentException('The audio analyzer result status is invalid.');
        }
    }

    /** @param  array<string, mixed>  $payload */
    public static function fromArray(array $payload): self
    {
        $itemId = $payload['itemId'] ?? null;
        $status = $payload['status'] ?? null;

        if (! is_int($itemId) || ! is_string($status)) {
            throw new InvalidArgumentException('The audio analyzer result is missing its item identity or status.');
        }

        $embedding = $payload['embedding'] ?? [];
        if (! is_array($embedding)) {
            throw new InvalidArgumentException('The audio analyzer embedding is invalid.');
        }
        foreach ($embedding as $value) {
            if (! is_numeric($value)) {
                throw new InvalidArgumentException('The audio analyzer embedding is invalid.');
            }
        }

        return new self(
            itemId: $itemId,
            status: $status,
            features: is_array($payload['features'] ?? null) ? $payload['features'] : [],
            embedding: array_map(static fn (mixed $value): float => (float) $value, array_values($embedding)),
            runtimeMs: is_int($payload['runtimeMs'] ?? null) ? $payload['runtimeMs'] : null,
            windowsAnalyzed: is_int($payload['windowsAnalyzed'] ?? null) ? $payload['windowsAnalyzed'] : null,
            timings: self::timings($payload['timings'] ?? null),
            hardware: is_array($payload['hardware'] ?? null) ? $payload['hardware'] : [],
            error: is_string($payload['error'] ?? null) ? $payload['error'] : null,
        );
    }

    /** @return array<string, int> */
    private static function timings(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        return array_filter(
            $payload,
            static fn (mixed $value, mixed $key): bool => is_string($key)
                && is_int($value)
                && $value >= 0,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
