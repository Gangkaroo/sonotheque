<?php

namespace App\Music\Enrichment\Data;

final readonly class ArtistImageInformation
{
    public function __construct(
        public string $imageUrl,
        public ?int $width,
        public ?int $height,
        public ?string $author,
        public ?string $licenseName,
        public ?string $licenseUrl,
        public ProviderAttribution $attribution,
        public ?string $providerReference = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'imageUrl' => $this->imageUrl,
            'width' => $this->width,
            'height' => $this->height,
            'author' => $this->author,
            'licenseName' => $this->licenseName,
            'licenseUrl' => $this->licenseUrl,
            'attribution' => $this->attribution->toArray(),
            'providerReference' => $this->providerReference,
        ];
    }
}
