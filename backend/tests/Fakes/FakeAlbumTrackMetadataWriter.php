<?php

namespace Tests\Fakes;

use App\Music\Metadata\TrackMetadataWriter;
use App\Music\Scanning\AudioMetadata;

class FakeAlbumTrackMetadataWriter implements TrackMetadataWriter
{
    public function supports(string $path): bool
    {
        return str_ends_with(mb_strtolower($path), '.mp3');
    }

    public function write(string $path, array $values): AudioMetadata
    {
        file_put_contents($path, 'written');

        return new AudioMetadata(
            album: $values['albumTitle'] ?? 'Album',
            albumArtist: $values['albumArtist'] ?? 'Artist',
            artists: $values['artistNames'] ?? ['Artist'],
            genres: $values['genres'] ?? ['Old genre'],
            year: $values['releaseYear'] ?? 2000,
            originalReleaseYear: $values['releaseYear'] ?? 2000,
            discTotal: $values['totalDiscs'] ?? null,
            comment: $values['comment'] ?? null,
            rawMetadata: ['verified' => true],
        );
    }
}
