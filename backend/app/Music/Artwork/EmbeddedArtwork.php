<?php

namespace App\Music\Artwork;

final readonly class EmbeddedArtwork
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
    ) {}
}
