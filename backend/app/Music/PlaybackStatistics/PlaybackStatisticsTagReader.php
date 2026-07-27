<?php

namespace App\Music\PlaybackStatistics;

use Carbon\CarbonImmutable;
use Throwable;

class PlaybackStatisticsTagReader
{
    public const IMPORT_VERSION = 1;

    private const FILETIME_UNIX_EPOCH_TICKS = 116_444_736_000_000_000;

    private const FILETIME_TICKS_PER_SECOND = 10_000_000;

    private const MAX_PLAUSIBLE_PLAY_COUNT = 1_000_000;

    private const MAX_UNSIGNED_INT32 = 4_294_967_295;

    private const COUNT_FIELDS = ['play_count', 'playcount', 'play_counter', 'pcnt'];

    private const FIRST_PLAYED_FIELDS = ['first_played_timestamp', 'first_played', 'firstplayed'];

    private const LAST_PLAYED_FIELDS = ['last_played_timestamp', 'last_played', 'lastplayed'];

    /** @param array<string, mixed> $metadata */
    public function read(array $metadata): ImportedPlayStatistics
    {
        $ignoredGlobalPlayCount = $this->globalPopularityPlayCount($metadata);
        $fields = $this->fields($metadata, $ignoredGlobalPlayCount !== null);
        $sourceFields = [];
        if ($ignoredGlobalPlayCount !== null) {
            $sourceFields['ignored_global_play_count'] = $ignoredGlobalPlayCount;
        }

        $warnings = [];
        $playCount = $this->playCount($fields, $sourceFields, $warnings);
        $firstPlayedAt = $this->date($fields, self::FIRST_PLAYED_FIELDS, 'first played', $sourceFields, $warnings);
        $lastPlayedAt = $this->date($fields, self::LAST_PLAYED_FIELDS, 'last played', $sourceFields, $warnings);

        return new ImportedPlayStatistics(
            playCount: $playCount,
            firstPlayedAt: $firstPlayedAt,
            lastPlayedAt: $lastPlayedAt,
            sourceFields: $sourceFields,
            warnings: $warnings,
        );
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $sourceFields
     * @param  list<string>  $warnings
     */
    private function playCount(
        array $fields,
        array &$sourceFields,
        array &$warnings,
    ): ?int {
        $field = $this->firstField($fields, self::COUNT_FIELDS);
        if ($field === null) {
            return null;
        }

        [$name, $value] = $field;
        $sourceFields[$name] = $value;
        if (preg_match('/^\d+$/', trim($value)) !== 1) {
            $warnings[] = "Playback-statistics tag {$name} does not contain a valid play count.";

            return null;
        }

        $playCount = (int) $value;
        if ($name === 'pcnt') {
            $playCount = $this->normalizePcntByteOrder($playCount);
        }

        return min($playCount, 2_147_483_647);
    }

    private function normalizePcntByteOrder(int $playCount): int
    {
        if ($playCount <= self::MAX_PLAUSIBLE_PLAY_COUNT || $playCount > self::MAX_UNSIGNED_INT32) {
            return $playCount;
        }

        $littleEndianValue = (($playCount & 0x000000FF) << 24)
            | (($playCount & 0x0000FF00) << 8)
            | (($playCount & 0x00FF0000) >> 8)
            | (($playCount & 0xFF000000) >> 24);

        return $littleEndianValue <= self::MAX_PLAUSIBLE_PLAY_COUNT
            && $littleEndianValue < $playCount
                ? $littleEndianValue
                : $playCount;
    }

    /** @param array<string, mixed> $metadata */
    private function globalPopularityPlayCount(array $metadata): ?string
    {
        $frames = $metadata['id3v2']['TXXX'] ?? null;
        if (! is_array($frames)) {
            return null;
        }

        $hasAmbiguousPlayCount = false;
        $hasGlobalPopularityField = false;
        foreach ($frames as $frame) {
            if (! is_array($frame) || ! is_string($frame['description'] ?? null)) {
                continue;
            }

            $description = trim($frame['description']);
            $hasAmbiguousPlayCount = $hasAmbiguousPlayCount || $description === 'PLAY COUNT';
            $hasGlobalPopularityField = $hasGlobalPopularityField || in_array(
                $this->normalizeKey($description),
                ['listeners', 'lastfmplaycount', 'lastfm_playcount'],
                true,
            );
        }

        if (! $hasAmbiguousPlayCount || ! $hasGlobalPopularityField) {
            return null;
        }

        return $this->exactFieldValue($metadata, 'PLAY COUNT');
    }

    /** @param array<string|int, mixed> $values */
    private function exactFieldValue(array $values, string $field): ?string
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $key === $field) {
                $scalar = $this->scalarValue($value);
                if ($scalar !== null && trim($scalar) !== '') {
                    return trim($scalar);
                }
            }

            if (is_array($value)) {
                $nested = $this->exactFieldValue($value, $field);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $fields
     * @param  list<string>  $candidates
     * @param  array<string, string>  $sourceFields
     * @param  list<string>  $warnings
     */
    private function date(
        array $fields,
        array $candidates,
        string $label,
        array &$sourceFields,
        array &$warnings,
    ): ?CarbonImmutable {
        $field = $this->firstField($fields, $candidates);
        if ($field === null) {
            return null;
        }

        [$name, $value] = $field;
        $sourceFields[$name] = $value;

        try {
            if (preg_match('/^\d{10}$/', trim($value)) === 1) {
                return CarbonImmutable::createFromTimestampUTC((int) $value);
            }

            if (preg_match('/^\d{17,18}$/', trim($value)) === 1) {
                return $this->fileTime(trim($value));
            }

            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            $warnings[] = "Playback-statistics tag {$name} does not contain a valid {$label} timestamp.";

            return null;
        }
    }

    private function fileTime(string $value): CarbonImmutable
    {
        $unixTicks = (int) $value - self::FILETIME_UNIX_EPOCH_TICKS;
        if ($unixTicks < 0) {
            throw new \InvalidArgumentException('The FILETIME value predates the Unix epoch.');
        }

        $seconds = intdiv($unixTicks, self::FILETIME_TICKS_PER_SECOND);
        $microseconds = intdiv($unixTicks % self::FILETIME_TICKS_PER_SECOND, 10);

        return CarbonImmutable::createFromTimestampUTC($seconds)->setMicrosecond($microseconds);
    }

    /**
     * @param  array<string, string>  $fields
     * @param  list<string>  $candidates
     * @return array{string, string}|null
     */
    private function firstField(array $fields, array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if (isset($fields[$candidate])) {
                return [$candidate, $fields[$candidate]];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, string>
     */
    private function fields(array $metadata, bool $ignoreGlobalPlayCount): array
    {
        $fields = [];
        $this->collectFields($metadata, $fields, $ignoreGlobalPlayCount);

        return $fields;
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @param  array<string, string>  $fields
     */
    private function collectFields(array $values, array &$fields, bool $ignoreGlobalPlayCount): void
    {
        foreach ($values as $key => $value) {
            $normalizedKey = $this->normalizeKey((string) $key);
            $isIgnoredGlobalPlayCount = $ignoreGlobalPlayCount && $key === 'PLAY COUNT';
            if (! $isIgnoredGlobalPlayCount
                && $this->isCandidate($normalizedKey)
                && ! isset($fields[$normalizedKey])) {
                $scalar = $this->scalarValue($value);
                if ($scalar !== null && trim($scalar) !== '') {
                    $fields[$normalizedKey] = trim($scalar);
                }
            }

            if (is_array($value)) {
                $this->collectFields($value, $fields, $ignoreGlobalPlayCount);
            }
        }
    }

    private function isCandidate(string $key): bool
    {
        return in_array($key, [
            ...self::COUNT_FIELDS,
            ...self::FIRST_PLAYED_FIELDS,
            ...self::LAST_PLAYED_FIELDS,
        ], true);
    }

    private function normalizeKey(string $key): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($key)) ?? '', '_');
    }

    private function scalarValue(mixed $value): ?string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return null;
        }

        if (isset($value['data']) && is_scalar($value['data'])) {
            return (string) $value['data'];
        }

        foreach ($value as $child) {
            $scalar = $this->scalarValue($child);
            if ($scalar !== null) {
                return $scalar;
            }
        }

        return null;
    }
}
