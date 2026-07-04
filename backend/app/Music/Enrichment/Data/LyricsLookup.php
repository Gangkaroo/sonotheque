<?php

namespace App\Music\Enrichment\Data;

use App\Music\Enrichment\Contracts\CacheableLookup;

final readonly class LyricsLookup implements CacheableLookup
{
    public function __construct(
        public int $trackId,
        public string $title,
        public string $artistName,
        public ?string $albumTitle = null,
        public ?int $durationSeconds = null,
    ) {
    }

    public function cachePayload(): array
    {
        return [
            'trackId' => $this->trackId,
            'title' => $this->title,
            'artistName' => $this->artistName,
            'albumTitle' => $this->albumTitle,
            'durationSeconds' => $this->durationSeconds,
        ];
    }
}
