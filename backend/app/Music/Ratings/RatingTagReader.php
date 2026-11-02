<?php

namespace App\Music\Ratings;

final class RatingTagReader
{
    public const IMPORT_VERSION = 1;

    public const POPULARIMETER_EMAIL = 'rating@sonotheque.local';

    public const ALBUM_RATING_DESCRIPTION = 'SONOTHEQUE_ALBUM_RATING';

    /** @var array<int, int> */
    private const SONOTHEQUE_POPM_VALUES = [
        1 => 13,
        2 => 23,
        3 => 54,
        4 => 64,
        5 => 118,
        6 => 128,
        7 => 186,
        8 => 196,
        9 => 242,
        10 => 255,
    ];

    /** @param array<string, mixed> $metadata */
    public function read(array $metadata): ImportedRatingTags
    {
        $warnings = [];
        [$trackHalfSteps, $trackTagPresent] = $this->trackRating($metadata, $warnings);
        [$albumHalfSteps, $albumTagPresent] = $this->userTextRating(
            $metadata,
            [self::ALBUM_RATING_DESCRIPTION],
            'album rating',
            $warnings,
        );

        return new ImportedRatingTags(
            $trackHalfSteps,
            $trackTagPresent,
            $albumHalfSteps,
            $albumTagPresent,
            $warnings,
        );
    }

    public function popularimeterValue(?int $halfSteps): int
    {
        return $halfSteps === null ? 0 : self::SONOTHEQUE_POPM_VALUES[$halfSteps];
    }

    /**
     * @param  list<string>  $warnings
     * @return array{int|null, bool}
     */
    private function trackRating(array $metadata, array &$warnings): array
    {
        $frames = $metadata['id3v2']['POPM'] ?? [];
        if (is_array($frames)) {
            $fallback = null;
            foreach ($frames as $frame) {
                if (! is_array($frame) || ! is_numeric($frame['rating'] ?? null)) {
                    continue;
                }

                $rating = max(0, min(255, (int) $frame['rating']));
                if (mb_strtolower((string) ($frame['email'] ?? '')) === self::POPULARIMETER_EMAIL) {
                    return [$this->sonothequePopmHalfSteps($rating), true];
                }
                $fallback ??= $rating;
            }
            if ($fallback !== null) {
                return [$this->genericPopmHalfSteps($fallback), true];
            }
        }

        return $this->userTextRating($metadata, ['RATING'], 'track rating', $warnings);
    }

    private function sonothequePopmHalfSteps(int $rating): ?int
    {
        if ($rating === 0) {
            return null;
        }

        $halfSteps = array_search($rating, self::SONOTHEQUE_POPM_VALUES, true);

        return is_int($halfSteps) ? $halfSteps : $this->genericPopmHalfSteps($rating);
    }

    private function genericPopmHalfSteps(int $rating): ?int
    {
        if ($rating === 0) {
            return null;
        }

        return match (true) {
            $rating <= 31 => 2,
            $rating <= 95 => 4,
            $rating <= 159 => 6,
            $rating <= 223 => 8,
            default => 10,
        };
    }

    /**
     * @param  list<string>  $descriptions
     * @param  list<string>  $warnings
     * @return array{int|null, bool}
     */
    private function userTextRating(
        array $metadata,
        array $descriptions,
        string $label,
        array &$warnings,
    ): array {
        $descriptions = array_map('mb_strtoupper', $descriptions);
        foreach ((array) ($metadata['id3v2']['TXXX'] ?? []) as $frame) {
            if (! is_array($frame)
                || ! in_array(mb_strtoupper(trim((string) ($frame['description'] ?? ''))), $descriptions, true)) {
                continue;
            }

            $value = $this->userTextValue($frame, $metadata);
            if ($value === null || $value === '0') {
                return [null, true];
            }
            if (! is_numeric($value)) {
                $warnings[] = "The {$label} tag does not contain a numeric value.";

                return [null, true];
            }

            $halfSteps = (int) round((float) $value * 2);
            if ($halfSteps < 1 || $halfSteps > 10 || abs(((float) $value * 2) - $halfSteps) > 0.001) {
                $warnings[] = "The {$label} tag must use half-star steps between 0.5 and 5.";

                return [null, true];
            }

            return [$halfSteps, true];
        }

        return [null, false];
    }

    /**
     * @param  array<string, mixed>  $frame
     * @param  array<string, mixed>  $metadata
     */
    private function userTextValue(array $frame, array $metadata): ?string
    {
        $data = $frame['data'] ?? null;
        if (! is_scalar($data)) {
            return null;
        }

        $value = (string) $data;
        $description = (string) ($frame['description'] ?? '');
        if (str_starts_with($value, '[binary data omitted:')) {
            foreach ([$metadata['id3v2']['comments']['text'] ?? [], $metadata['comments']['text'] ?? []] as $values) {
                foreach ((array) $values as $name => $decoded) {
                    if (is_string($name) && mb_strtoupper(trim($name)) === mb_strtoupper(trim($description))) {
                        $decoded = is_array($decoded) ? reset($decoded) : $decoded;
                        if (is_scalar($decoded)) {
                            return trim((string) $decoded);
                        }
                    }
                }
            }
        }
        $encoding = (int) ($frame['encodingid'] ?? 0);
        if (in_array($encoding, [1, 2], true) && str_contains($value, "\0")) {
            $source = $encoding === 2 || str_starts_with($value, "\xFE\xFF") ? 'UTF-16BE' : 'UTF-16LE';
            $value = mb_convert_encoding($value, 'UTF-8', $source);
        }
        if (str_starts_with($value, $description)) {
            $value = substr($value, strlen($description));
        }

        return trim($value, "\0\xEF\xBB\xBF \t\n\r");
    }
}
