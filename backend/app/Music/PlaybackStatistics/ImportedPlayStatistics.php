<?php

namespace App\Music\PlaybackStatistics;

use Carbon\CarbonImmutable;

final readonly class ImportedPlayStatistics
{
    /**
     * @param  array<string, string>  $sourceFields
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?int $playCount = null,
        public ?CarbonImmutable $firstPlayedAt = null,
        public ?CarbonImmutable $lastPlayedAt = null,
        public array $sourceFields = [],
        public array $warnings = [],
    ) {}

    public function hasValues(): bool
    {
        return $this->playCount !== null
            || $this->firstPlayedAt !== null
            || $this->lastPlayedAt !== null;
    }

    /** @return array<string, mixed> */
    public function sourceMetadata(): array
    {
        return [
            'play_count' => $this->playCount,
            'first_played_at' => $this->firstPlayedAt?->toJSON(),
            'last_played_at' => $this->lastPlayedAt?->toJSON(),
            'source_fields' => $this->sourceFields,
            'merge_strategy' => 'non_decreasing_max',
        ];
    }
}
