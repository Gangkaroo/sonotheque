<?php

namespace App\Music\Scanning;

use App\Music\Artwork\EmbeddedArtwork;

final readonly class AudioMetadata
{
    /**
     * @param  list<string>  $artists
     * @param  list<string>  $composers
     * @param  list<string>  $performers
     * @param  list<string>  $genres
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $rawMetadata
     */
    public function __construct(
        public ?string $title = null,
        public ?string $album = null,
        public ?string $albumArtist = null,
        public array $artists = [],
        public array $composers = [],
        public array $performers = [],
        public ?string $comment = null,
        public array $genres = [],
        public ?int $year = null,
        public ?int $originalReleaseYear = null,
        public ?int $trackNumber = null,
        public ?int $discNumber = null,
        public ?int $discTotal = null,
        public ?int $durationMs = null,
        public ?string $mimeType = null,
        public ?string $container = null,
        public ?string $codec = null,
        public ?int $bitrate = null,
        public ?int $sampleRate = null,
        public ?int $channels = null,
        public ?EmbeddedArtwork $embeddedArtwork = null,
        public array $warnings = [],
        public array $rawMetadata = [],
    ) {}
}
