<?php

namespace Tests\Fakes;

use App\Music\Metadata\TrackMetadataWriter;
use App\Music\Scanning\AudioMetadata;

class FakeTrackMetadataWriter implements TrackMetadataWriter
{
    public function supports(string $path): bool
    {
        return str_ends_with(mb_strtolower($path), '.mp3');
    }

    public function write(string $path, array $values): AudioMetadata
    {
        file_put_contents($path, 'written');

        return new AudioMetadata(
            title: $values['title'],
            artists: $values['artistNames'],
            composers: $values['composers'],
            performers: $values['performers'],
            genres: $values['genres'],
            comment: $values['comment'],
            year: $values['year'],
            trackNumber: $values['trackNumber'],
            discNumber: $values['discNumber'],
            rawMetadata: ['verified' => true],
        );
    }
}
