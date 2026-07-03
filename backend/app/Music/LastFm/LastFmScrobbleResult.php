<?php

namespace App\Music\LastFm;

final readonly class LastFmScrobbleResult
{
    public function __construct(
        public bool $accepted,
        public ?int $ignoredCode = null,
        public ?string $message = null,
    ) {
    }
}
