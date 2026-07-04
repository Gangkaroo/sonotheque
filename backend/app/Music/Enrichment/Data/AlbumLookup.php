<?php

namespace App\Music\Enrichment\Data;

use App\Music\Enrichment\Contracts\CacheableLookup;

final readonly class AlbumLookup implements CacheableLookup
{
    /** @param array<string, string> $externalIds */
    public function __construct(
        public int $albumId,
        public string $title,
        public string $artistName,
        public ?int $releaseYear = null,
        public array $externalIds = [],
        public string $language = 'en',
    ) {
    }

    public function cachePayload(): array
    {
        return [
            'albumId' => $this->albumId,
            'title' => $this->title,
            'artistName' => $this->artistName,
            'releaseYear' => $this->releaseYear,
            'externalIds' => $this->externalIds,
            'language' => $this->language,
        ];
    }
}
