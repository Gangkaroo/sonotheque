<?php

namespace App\Music\Enrichment\Data;

final readonly class ArtistInformation
{
    /** @param list<string> $tags */
    public function __construct(
        public string $name,
        public ?string $biography,
        public ?string $country,
        public ?string $activeFrom,
        public ?string $activeTo,
        public array $tags,
        public ProviderAttribution $attribution,
        public ?string $providerReference = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'biography' => $this->biography,
            'country' => $this->country,
            'activeFrom' => $this->activeFrom,
            'activeTo' => $this->activeTo,
            'tags' => $this->tags,
            'attribution' => $this->attribution->toArray(),
            'providerReference' => $this->providerReference,
        ];
    }
}
