<?php

namespace App\Music\Scanning;

final readonly class ProbedAudioMetadata
{
    /**
     * @param  array<string, string>  $tags
     * @param  array<string, mixed>  $rawMetadata
     */
    public function __construct(
        public array $tags = [],
        public ?int $durationMs = null,
        public ?string $container = null,
        public ?string $codec = null,
        public ?int $bitrate = null,
        public ?int $sampleRate = null,
        public ?int $channels = null,
        public array $rawMetadata = [],
    ) {
    }
}
