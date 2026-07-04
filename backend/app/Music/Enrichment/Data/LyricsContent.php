<?php

namespace App\Music\Enrichment\Data;

final readonly class LyricsContent
{
    public function __construct(
        public ?string $plainLyrics,
        public ?string $synchronizedLyrics,
        public ?string $language,
        public ProviderAttribution $attribution,
        public ?string $providerReference = null,
        public bool $instrumental = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plainLyrics' => $this->plainLyrics,
            'synchronizedLyrics' => $this->synchronizedLyrics,
            'language' => $this->language,
            'attribution' => $this->attribution->toArray(),
            'providerReference' => $this->providerReference,
            'instrumental' => $this->instrumental,
        ];
    }
}
