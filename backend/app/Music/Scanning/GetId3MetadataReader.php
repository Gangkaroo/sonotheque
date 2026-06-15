<?php

namespace App\Music\Scanning;

use App\Music\Artwork\EmbeddedArtwork;
use RuntimeException;

class GetId3MetadataReader implements AudioMetadataReader
{
    public function __construct(private readonly RawMetadataSanitizer $metadataSanitizer) {}

    public function read(string $absolutePath): AudioMetadata
    {
        $getId3 = new \getID3;
        $information = $getId3->analyze($absolutePath);
        \getid3_lib::CopyTagsToComments($information);

        if (! empty($information['error'])) {
            throw new RuntimeException(implode(' ', (array) $information['error']));
        }

        $comments = $information['comments'] ?? [];
        [$trackNumber] = $this->fraction($this->first($comments, ['track_number', 'tracknumber']));
        [$discNumber, $discTotal] = $this->fraction($this->first($comments, ['part_of_a_set', 'discnumber', 'disc_number']));
        $year = $this->year($this->first($comments, ['year', 'date']));
        $originalReleaseYear = $this->year($this->first($comments, ['original_release_date', 'originaldate', 'original_year'])) ?? $year;

        return new AudioMetadata(
            title: $this->first($comments, ['title']),
            album: $this->first($comments, ['album']),
            albumArtist: $this->first($comments, ['album_artist', 'band']),
            artists: $this->values($comments, ['artist', 'artists']),
            genres: $this->values($comments, ['genre']),
            year: $year,
            originalReleaseYear: $originalReleaseYear,
            trackNumber: $trackNumber,
            discNumber: $discNumber,
            discTotal: $discTotal,
            durationMs: isset($information['playtime_seconds']) ? (int) round($information['playtime_seconds'] * 1000) : null,
            mimeType: $information['mime_type'] ?? null,
            container: $information['fileformat'] ?? null,
            codec: $information['audio']['codec'] ?? $information['audio']['dataformat'] ?? null,
            bitrate: isset($information['audio']['bitrate']) ? (int) round($information['audio']['bitrate']) : null,
            sampleRate: isset($information['audio']['sample_rate']) ? (int) $information['audio']['sample_rate'] : null,
            channels: isset($information['audio']['channels']) ? (int) $information['audio']['channels'] : null,
            embeddedArtwork: $this->embeddedArtwork($information),
            warnings: array_values((array) ($information['warning'] ?? [])),
            rawMetadata: $this->metadataSanitizer->sanitize($this->withoutArtwork($information)),
        );
    }

    /** @param array<string, mixed> $comments */
    private function first(array $comments, array $keys): ?string
    {
        return $this->values($comments, $keys)[0] ?? null;
    }

    /** @param array<string, mixed> $comments
     * @return list<string>
     */
    private function values(array $comments, array $keys): array
    {
        foreach ($keys as $key) {
            if (! isset($comments[$key])) {
                continue;
            }

            $values = array_values(array_filter(
                array_map(static fn (mixed $value): string => trim((string) $value), (array) $comments[$key]),
                static fn (string $value): bool => $value !== '',
            ));

            if ($values !== []) {
                return array_values(array_unique($values));
            }
        }

        return [];
    }

    /** @return array{0: ?int, 1: ?int} */
    private function fraction(?string $value): array
    {
        if ($value === null || preg_match('/^(\d+)(?:\s*\/\s*(\d+))?/', $value, $matches) !== 1) {
            return [null, null];
        }

        return [(int) $matches[1], isset($matches[2]) ? (int) $matches[2] : null];
    }

    private function year(?string $value): ?int
    {
        return $value !== null && preg_match('/\b(\d{4})\b/', $value, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    /** @param array<string, mixed> $information
     * @return array<string, mixed>
     */
    private function withoutArtwork(array $information): array
    {
        return $this->removeArtworkPayloads($information);
    }

    /** @param array<string, mixed> $information */
    private function embeddedArtwork(array $information): ?EmbeddedArtwork
    {
        $pictures = array_values((array) ($information['comments']['picture'] ?? []));
        usort(
            $pictures,
            static function (mixed $left, mixed $right): int {
                $leftIsFrontCover = is_array($left) && (int) ($left['picturetypeid'] ?? -1) === 3;
                $rightIsFrontCover = is_array($right) && (int) ($right['picturetypeid'] ?? -1) === 3;

                return $rightIsFrontCover <=> $leftIsFrontCover;
            },
        );

        foreach ($pictures as $picture) {
            if (! is_array($picture) || ! is_string($picture['data'] ?? null) || ! is_string($picture['image_mime'] ?? null)) {
                continue;
            }

            return new EmbeddedArtwork($picture['data'], $picture['image_mime']);
        }

        return null;
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @return array<string|int, mixed>
     */
    private function removeArtworkPayloads(array $value): array
    {
        if (isset($value['data'], $value['image_mime'])) {
            unset($value['data']);
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->removeArtworkPayloads($child);
            }
        }

        return $value;
    }
}
