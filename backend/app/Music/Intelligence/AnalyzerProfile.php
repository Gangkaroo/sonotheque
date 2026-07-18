<?php

namespace App\Music\Intelligence;

use InvalidArgumentException;

final readonly class AnalyzerProfile
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(
        public string $key,
        public int $protocolVersion,
        public string $analyzerName,
        public string $analyzerVersion,
        public string $analyzerLicense,
        public string $modelName,
        public string $modelVersion,
        public string $modelChecksum,
        public string $modelLicense,
        public int $embeddingDimensions,
        public int $sampleRate,
        public array $manifest = [],
    ) {
        if ($this->protocolVersion !== 1) {
            throw new InvalidArgumentException('The audio analyzer protocol version is not supported.');
        }

        if ($this->embeddingDimensions < 1 || $this->embeddingDimensions > 4096) {
            throw new InvalidArgumentException('The audio analyzer embedding dimensions are invalid.');
        }

        if (preg_match('/^[a-f0-9]{64}$/', $this->modelChecksum) !== 1) {
            throw new InvalidArgumentException('The audio analyzer model checksum is invalid.');
        }
    }

    /** @param  array<string, mixed>  $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            key: self::text($payload, 'key'),
            protocolVersion: self::integer($payload, 'protocolVersion'),
            analyzerName: self::text($payload, 'analyzerName'),
            analyzerVersion: self::text($payload, 'analyzerVersion'),
            analyzerLicense: self::text($payload, 'analyzerLicense'),
            modelName: self::text($payload, 'modelName'),
            modelVersion: self::text($payload, 'modelVersion'),
            modelChecksum: mb_strtolower(self::text($payload, 'modelChecksum')),
            modelLicense: self::text($payload, 'modelLicense'),
            embeddingDimensions: self::integer($payload, 'embeddingDimensions'),
            sampleRate: self::integer($payload, 'sampleRate'),
            manifest: is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'protocolVersion' => $this->protocolVersion,
            'analyzerName' => $this->analyzerName,
            'analyzerVersion' => $this->analyzerVersion,
            'analyzerLicense' => $this->analyzerLicense,
            'modelName' => $this->modelName,
            'modelVersion' => $this->modelVersion,
            'modelChecksum' => $this->modelChecksum,
            'modelLicense' => $this->modelLicense,
            'embeddingDimensions' => $this->embeddingDimensions,
            'sampleRate' => $this->sampleRate,
            'manifest' => $this->manifest,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private static function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("The audio analyzer profile field [{$key}] is invalid.");
        }

        return trim($value);
    }

    /** @param  array<string, mixed>  $payload */
    private static function integer(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (! is_int($value)) {
            throw new InvalidArgumentException("The audio analyzer profile field [{$key}] is invalid.");
        }

        return $value;
    }
}
