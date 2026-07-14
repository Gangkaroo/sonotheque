<?php

namespace App\Music\Scanning;

use App\Music\Artwork\EmbeddedArtwork;
use RuntimeException;
use Throwable;

class GetId3MetadataReader implements AudioMetadataReader
{
    private const MAX_DIAGNOSTICS = 10;

    private const NON_ACTIONABLE_WARNINGS = [
        'Some ID3v1 fields do not use NULL characters for padding',
    ];

    private const NON_ACTIONABLE_WARNING_PATTERNS = [
        '/^Unknown data before synch .+\. This is a known problem with some versions of LAME \(3\.90\s*-\s*3\.92\) DLL in CBR mode\.$/',
    ];

    private const INVALID_ID3_FRAME_ERROR = 'Next ID3v2 frame is also invalid, aborting processing.';

    private const RECOVERED_INVALID_ID3_FRAME_WARNING = 'Part of a malformed ID3v2 tag could not be read. '
        .'FFprobe recovered the main tags and found a valid audio stream, but optional metadata or embedded artwork '
        .'after the damaged frame may be unavailable.';

    public function __construct(
        private readonly RawMetadataSanitizer $metadataSanitizer,
        private readonly ?AudioMetadataProbe $fallbackProbe = null,
    ) {
    }

    public function read(string $absolutePath): AudioMetadata
    {
        $information = $this->analyze($absolutePath);
        $errors = array_values((array) ($information['error'] ?? []));
        $fallback = $errors === [] ? null : $this->fallback($absolutePath, $errors);
        $comments = $this->preferredComments($information, $fallback);
        $warnings = $this->diagnostics($information, $errors, $fallback !== null);
        [$trackNumber] = $this->fraction(
            $this->first($comments, ['track_number', 'tracknumber']),
            'track',
            $warnings,
        );
        [$discNumber, $discTotal] = $this->fraction(
            $this->first($comments, ['part_of_a_set', 'discnumber', 'disc_number']),
            'disc',
            $warnings,
        );
        $year = $this->year($this->first($comments, ['year', 'date']));
        $originalReleaseYear = $this->year($this->first($comments, ['original_release_date', 'originaldate', 'original_year'])) ?? $year;
        $rawMetadata = $this->withoutArtwork($information);

        if ($fallback !== null) {
            $rawMetadata['ffprobe_fallback'] = $fallback->rawMetadata;
        }

        return new AudioMetadata(
            title: $this->first($comments, ['title']),
            album: $this->first($comments, ['album']),
            albumArtist: $this->first($comments, ['album_artist', 'band']),
            artists: $this->values($comments, ['artist', 'artists']),
            composers: $this->values($comments, ['composer']),
            performers: $this->values($comments, ['performer', 'conductor']),
            comment: $this->comment($information, $comments),
            genres: $this->genres($information, $comments),
            year: $year,
            originalReleaseYear: $originalReleaseYear,
            trackNumber: $trackNumber,
            discNumber: $discNumber,
            discTotal: $discTotal,
            durationMs: isset($information['playtime_seconds'])
                ? (int) round($information['playtime_seconds'] * 1000)
                : $fallback?->durationMs,
            mimeType: $information['mime_type'] ?? $this->mimeType($fallback?->container),
            container: $information['fileformat'] ?? $this->container($fallback?->container),
            codec: $information['audio']['codec'] ?? $information['audio']['dataformat'] ?? $fallback?->codec,
            bitrate: isset($information['audio']['bitrate'])
                ? (int) round($information['audio']['bitrate'])
                : $fallback?->bitrate,
            sampleRate: isset($information['audio']['sample_rate'])
                ? (int) $information['audio']['sample_rate']
                : $fallback?->sampleRate,
            channels: isset($information['audio']['channels'])
                ? (int) $information['audio']['channels']
                : $fallback?->channels,
            embeddedArtwork: $this->embeddedArtwork($information),
            warnings: $warnings,
            rawMetadata: $this->metadataSanitizer->sanitize($rawMetadata),
        );
    }

    /** @return array<string, mixed> */
    protected function analyze(string $absolutePath): array
    {
        $getId3 = new \getID3();
        $information = $getId3->analyze($absolutePath);
        \getid3_lib::CopyTagsToComments($information);

        return $information;
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
                array_map(fn (mixed $value): string => $this->text($value), (array) $comments[$key]),
                static fn (string $value): bool => $value !== '',
            ));

            if ($values !== []) {
                return array_values(array_unique($values));
            }
        }

        return [];
    }

    /**
     * Described ID3 comment frames are commonly used for technical values such as
     * iTunNORM. Only an undescribed COMM frame represents the editable comment.
     *
     * @param  array<string, mixed>  $information
     * @param  array<string, mixed>  $comments
     */
    private function comment(array $information, array $comments): ?string
    {
        $frames = $information['id3v2']['COMM'] ?? null;
        if (! is_array($frames)) {
            return $this->first($comments, ['comment']);
        }

        $decodedComments = $information['id3v2']['comments']['comment'] ?? null;
        if (is_array($decodedComments)) {
            foreach ($decodedComments as $description => $value) {
                if (! is_int($description) || ! is_scalar($value)) {
                    continue;
                }

                if (! mb_check_encoding((string) $value, 'UTF-8')) {
                    continue;
                }

                $comment = trim((string) $value, "\0 \t\n\r\x0B");
                if ($comment !== '') {
                    return $comment;
                }
            }
        }

        foreach ($frames as $frame) {
            if (! is_array($frame)
                || trim((string) ($frame['description'] ?? '')) !== ''
                || ! is_string($frame['data'] ?? null)) {
                continue;
            }

            $encoding = is_string($frame['encoding'] ?? null) ? $frame['encoding'] : 'ISO-8859-1';
            $comment = trim(mb_convert_encoding($frame['data'], 'UTF-8', $encoding), "\0 \t\n\r\x0B");
            if ($comment !== '') {
                return $comment;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $information
     * @param  array<string, mixed>  $comments
     * @return list<string>
     */
    private function genres(array $information, array $comments): array
    {
        $rawGenres = $this->id3v2TextValues($information, 'TCON');
        if ($rawGenres === []) {
            return $this->values($comments, ['genre']);
        }

        $genres = [];
        foreach ($rawGenres as $rawGenre) {
            array_push($genres, ...$this->normalizeGenre($rawGenre));
        }

        $seen = [];

        return array_values(array_filter($genres, static function (string $genre) use (&$seen): bool {
            $key = mb_strtolower($genre);
            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        }));
    }

    /** @return list<string> */
    private function normalizeGenre(string $genre): array
    {
        $genre = trim($genre);
        if ($genre === '') {
            return [];
        }

        if (preg_match('/^\d{1,3}$/', $genre) === 1) {
            return [$this->legacyGenreName($genre, $genre)];
        }

        if (preg_match(
            '/^(?<references>(?:\((?:\d{1,3}|RX|CR)\))+)(?<custom>.*)$/i',
            $genre,
            $matches,
        ) !== 1) {
            return [$genre];
        }

        preg_match_all('/\((\d{1,3}|RX|CR)\)/i', $matches['references'], $references);
        $genres = array_map(
            fn (string $reference): string => $this->legacyGenreName($reference, "({$reference})"),
            $references[1],
        );

        $custom = str_replace('((', '(', trim($matches['custom']));
        foreach (preg_split('/\s*;\s*/', $custom) ?: [] as $value) {
            if ($value === '') {
                continue;
            }

            $genres[] = preg_match('/^\d{1,3}$/', $value) === 1
                ? $this->legacyGenreName($value, $value)
                : $value;
        }

        return $genres;
    }

    private function legacyGenreName(string $reference, string $fallback): string
    {
        $name = \getid3_id3v1::LookupGenreName(strtoupper($reference));

        return is_string($name) ? $name : $fallback;
    }

    /** @param array<string, mixed> $information
     * @return list<string>
     */
    private function id3v2TextValues(array $information, string $frameId): array
    {
        $frames = $information['id3v2'][$frameId] ?? null;
        if (! is_array($frames)) {
            return [];
        }

        $values = [];
        foreach ($frames as $frame) {
            if (! is_array($frame) || ! is_string($frame['data'] ?? null) || ! is_string($frame['encoding'] ?? null)) {
                continue;
            }

            $decoded = mb_convert_encoding($frame['data'], 'UTF-8', $frame['encoding']);
            foreach (preg_split('/\x00+/', $decoded) ?: [] as $value) {
                $value = trim($value);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  list<string>  $warnings
     * @return array{0: ?int, 1: ?int}
     */
    private function fraction(?string $value, string $label, array &$warnings): array
    {
        if ($value === null || preg_match('/^(\d+)(?:\s*\/\s*(\d+))?/', $value, $matches) !== 1) {
            return [null, null];
        }

        return [
            $this->smallInteger($matches[1], "{$label} number", $warnings),
            isset($matches[2]) ? $this->smallInteger($matches[2], "total {$label}s", $warnings) : null,
        ];
    }

    /** @param  list<string>  $warnings */
    private function smallInteger(string $value, string $label, array &$warnings): ?int
    {
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $withinRange = strlen($normalized) < 5
            || (strlen($normalized) === 5 && strcmp($normalized, '32767') <= 0);

        if ($withinRange) {
            return (int) $normalized;
        }

        $warnings[] = "The {$label} tag value [{$value}] is outside the supported range and was ignored.";

        return null;
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
    private function preferredComments(array $information, ?ProbedAudioMetadata $fallback = null): array
    {
        $comments = is_array($information['comments'] ?? null) ? $information['comments'] : [];
        $id3v2Comments = is_array($information['id3v2']['comments'] ?? null)
            ? $information['id3v2']['comments']
            : [];

        return array_replace($this->fallbackComments($fallback), $comments, $id3v2Comments);
    }

    /** @return array<string, list<string>> */
    private function fallbackComments(?ProbedAudioMetadata $fallback): array
    {
        if ($fallback === null) {
            return [];
        }

        $comments = [];
        foreach ($fallback->tags as $key => $value) {
            $normalized = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($key)) ?? '', '_');
            $normalized = match ($normalized) {
                'albumartist' => 'album_artist',
                'track' => 'track_number',
                'disc' => 'part_of_a_set',
                default => $normalized,
            };

            if ($normalized !== '') {
                $comments[$normalized] = [$value];
            }
        }

        return $comments;
    }

    /**
     * @param  list<mixed>  $errors
     * @return list<string>
     */
    private function fallback(string $absolutePath, array $errors): ProbedAudioMetadata
    {
        if ($this->fallbackProbe === null) {
            throw new RuntimeException(implode(' ', array_map('strval', $errors)));
        }

        try {
            return $this->fallbackProbe->probe($absolutePath);
        } catch (Throwable $exception) {
            $primary = implode(' ', array_map('strval', $errors));

            throw new RuntimeException(
                trim("{$primary} FFprobe fallback failed: {$exception->getMessage()}"),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $information
     * @param  list<mixed>  $errors
     * @return list<string>
     */
    private function diagnostics(array $information, array $errors, bool $usedFallback): array
    {
        $parserWarnings = array_map('trim', array_map('strval', (array) ($information['warning'] ?? [])));
        $recoveredInvalidFrame = $usedFallback
            && in_array(self::INVALID_ID3_FRAME_ERROR, $errors, true)
            && array_any($parserWarnings, self::isInvalidId3FrameWarning(...));

        if ($recoveredInvalidFrame) {
            $errors = array_values(array_filter(
                $errors,
                static fn (mixed $error): bool => $error !== self::INVALID_ID3_FRAME_ERROR,
            ));
            $parserWarnings = array_values(array_filter(
                $parserWarnings,
                static fn (string $warning): bool => ! self::isInvalidId3FrameWarning($warning),
            ));
        }

        $errorDiagnostics = array_map(
            static fn (mixed $error): string => ($usedFallback ? 'FFprobe fallback used after getID3 error: ' : '').strval($error),
            $errors,
        );
        $warningDiagnostics = array_values(array_unique(array_filter(
            $parserWarnings,
            self::isActionableWarning(...),
        )));

        if ($recoveredInvalidFrame) {
            $warningDiagnostics[] = self::RECOVERED_INVALID_ID3_FRAME_WARNING;
        }

        if ($usedFallback && count($warningDiagnostics) > 2) {
            $omitted = count($warningDiagnostics) - 2;
            $warningDiagnostics = [
                ...array_slice($warningDiagnostics, 0, 2),
                "{$omitted} additional getID3 warnings were omitted after FFprobe validated the audio stream.",
            ];
        }

        $diagnostics = array_values(array_unique(array_filter(
            array_map('trim', [...$errorDiagnostics, ...$warningDiagnostics]),
            static fn (string $warning): bool => $warning !== '',
        )));

        if (count($diagnostics) <= self::MAX_DIAGNOSTICS) {
            return $diagnostics;
        }

        $omitted = count($diagnostics) - self::MAX_DIAGNOSTICS + 1;

        return [
            ...array_slice($diagnostics, 0, self::MAX_DIAGNOSTICS - 1),
            "{$omitted} additional parser diagnostics were omitted.",
        ];
    }

    private static function isActionableWarning(string $warning): bool
    {
        if ($warning === '' || in_array($warning, self::NON_ACTIONABLE_WARNINGS, true)) {
            return false;
        }

        return array_all(
            self::NON_ACTIONABLE_WARNING_PATTERNS,
            static fn (string $pattern): bool => preg_match($pattern, $warning) !== 1,
        );
    }

    private static function isInvalidId3FrameWarning(string $warning): bool
    {
        return str_starts_with($warning, 'error parsing "')
            && str_contains($warning, '!IsValidID3v2FrameName');
    }

    private function text(mixed $value): string
    {
        $text = (string) $value;
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return trim($text);
    }

    private function container(?string $formats): ?string
    {
        return $formats !== null ? explode(',', $formats)[0] : null;
    }

    private function mimeType(?string $formats): ?string
    {
        return match ($this->container($formats)) {
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            default => null,
        };
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
