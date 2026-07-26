<?php

namespace App\Music\Intelligence;

final readonly class AudioAnalyzerHealth
{
    public const STATUSES = [
        'not_configured',
        'unchecked',
        'dependency_missing',
        'model_missing',
        'ready',
        'incompatible',
        'error',
    ];

    public function __construct(
        public string $status,
        public ?string $message = null,
        public ?AnalyzerProfile $profile = null,
    ) {
    }

    public function ready(): bool
    {
        return $this->status === 'ready' && $this->profile !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'profile' => $this->profile?->toArray(),
        ];
    }
}
