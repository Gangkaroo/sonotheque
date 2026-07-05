<?php

namespace App\Music\Enrichment\Data;

use App\Music\Enrichment\Contracts\CacheableLookup;

final readonly class ArtistLookup implements CacheableLookup
{
    /** @param array<string, string> $externalIds */
    public function __construct(
        public int $artistId,
        public string $name,
        public array $externalIds = [],
        public string $language = 'en',
        public ?string $cacheVariant = null,
    ) {
    }

    public function cachePayload(): array
    {
        return array_filter([
            'artistId' => $this->artistId,
            'name' => $this->name,
            'externalIds' => $this->externalIds,
            'language' => $this->language,
            'cacheVariant' => $this->cacheVariant,
        ], static fn (mixed $value, string $key): bool => $key !== 'cacheVariant' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }
}
