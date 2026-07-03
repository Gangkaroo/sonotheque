<?php

namespace Tests\Fakes;

use App\Music\Scanning\AudioMetadata;
use App\Music\Scanning\AudioMetadataReader;

class TestId3MetadataReader implements AudioMetadataReader
{
    public function read(string $absolutePath): AudioMetadata
    {
        $information = (new \getID3())->analyze($absolutePath);
        \getid3_lib::CopyTagsToComments($information);
        $comments = $information['comments'] ?? [];

        return new AudioMetadata(
            title: $comments['title'][0] ?? null,
            album: $comments['album'][0] ?? null,
            albumArtist: $comments['album_artist'][0] ?? $comments['band'][0] ?? null,
            artists: array_values($comments['artist'] ?? []),
            composers: array_values($comments['composer'] ?? []),
            performers: array_values($comments['performer'] ?? $comments['conductor'] ?? []),
            comment: $comments['comment'][0] ?? null,
            genres: array_values($comments['genre'] ?? []),
            year: $this->number($comments['year'][0] ?? $comments['date'][0] ?? null),
            originalReleaseYear: $this->number($comments['original_year'][0] ?? $comments['year'][0] ?? null),
            trackNumber: $this->number($comments['track_number'][0] ?? null),
            discNumber: $this->number($comments['part_of_a_set'][0] ?? null),
            discTotal: $this->total($comments['part_of_a_set'][0] ?? null),
            rawMetadata: $information,
        );
    }

    private function number(mixed $value): ?int
    {
        return is_scalar($value) && preg_match('/^\d+/', (string) $value, $matches) === 1
            ? (int) $matches[0]
            : null;
    }

    private function total(mixed $value): ?int
    {
        return is_scalar($value) && preg_match('/^\d+\/(\d+)/', (string) $value, $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}
