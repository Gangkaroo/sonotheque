<?php

namespace App\Music\Artwork;

use App\Models\Artwork;

final readonly class ArtworkSyncResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public ?Artwork $artwork,
        public array $warnings = [],
        public bool $requiresEmbeddedFallback = false,
    ) {
    }
}
