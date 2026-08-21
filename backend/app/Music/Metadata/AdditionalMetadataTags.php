<?php

namespace App\Music\Metadata;

final class AdditionalMetadataTags
{
    /** @var list<string> */
    private const PLAYBACK_STATISTIC_NAMES = [
        'play_count',
        'playcount',
        'play_counter',
        'first_played_timestamp',
        'first_played',
        'firstplayed',
        'last_played_timestamp',
        'last_played',
        'lastplayed',
    ];

    /** @var list<string> */
    private const STANDARD_FRAME_IDS = [
        'APIC',
        'TALB',
        'TCOM',
        'TCON',
        'TDOR',
        'TDRC',
        'TIT2',
        'TORY',
        'TPE1',
        'TPE2',
        'TPE3',
        'TPOS',
        'TRCK',
        'TYER',
    ];

    /**
     * @param  array<string, mixed>  $rawMetadata
     * @return list<array{key: string, frameId: string, name: string, values: list<string>, sizeBytes: ?int, playbackStatistic: bool}>
     */
    public function extract(array $rawMetadata): array
    {
        $id3v2 = is_array($rawMetadata['id3v2'] ?? null) ? $rawMetadata['id3v2'] : [];
        $comments = is_array($id3v2['comments'] ?? null) ? $id3v2['comments'] : [];
        $tags = [];

        foreach ($id3v2 as $frameId => $frames) {
            if (! is_string($frameId)
                || preg_match('/^[A-Z0-9]{4}$/', $frameId) !== 1
                || in_array($frameId, self::STANDARD_FRAME_IDS, true)
                || ! is_array($frames)) {
                continue;
            }

            foreach (array_is_list($frames) ? $frames : [$frames] as $frame) {
                if (! is_array($frame)) {
                    continue;
                }

                $description = trim((string) ($frame['description'] ?? ''));
                if ($frameId === 'COMM' && $description === '') {
                    continue;
                }

                $key = in_array($frameId, ['COMM', 'TXXX'], true) && $description !== ''
                    ? $frameId.':'.mb_strtoupper($description)
                    : $frameId;
                $values = $this->values($frameId, $frame, $comments, $description);
                $tags[$key] ??= [
                    'key' => $key,
                    'frameId' => $frameId,
                    'name' => $this->name($frameId, $frame, $description),
                    'values' => [],
                    'sizeBytes' => null,
                    'playbackStatistic' => $this->isPlaybackStatistic($frameId, $description),
                ];
                $tags[$key]['values'] = array_values(array_unique([
                    ...$tags[$key]['values'],
                    ...$values,
                ]));
                $size = $this->positiveInteger($frame['datalength'] ?? null);
                if ($size !== null) {
                    $tags[$key]['sizeBytes'] = ($tags[$key]['sizeBytes'] ?? 0) + $size;
                }
            }
        }

        ksort($tags, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($tags);
    }

    /**
     * @param  array<string, mixed>  $rawMetadata
     * @return list<string>
     */
    public function keys(array $rawMetadata): array
    {
        return array_column($this->extract($rawMetadata), 'key');
    }

    /**
     * @param  array<string, mixed>  $rawMetadata
     * @return list<string>
     */
    public function playbackStatisticKeys(array $rawMetadata): array
    {
        return collect($this->extract($rawMetadata))
            ->where('playbackStatistic', true)
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $frame
     * @param  array<string, mixed>  $comments
     * @return list<string>
     */
    private function values(string $frameId, array $frame, array $comments, string $description): array
    {
        if (in_array($frameId, ['COMM', 'TXXX'], true) && $description !== '') {
            return $this->describedFrameValues($frame, $comments, $description);
        }

        $keys = array_filter([
            $this->normalizedKey($description),
            is_string($frame['framenameshort'] ?? null) ? $frame['framenameshort'] : null,
        ]);
        $values = [];
        foreach ($keys as $key) {
            foreach ((array) ($comments[$key] ?? []) as $value) {
                if (is_scalar($value)) {
                    $values[] = trim((string) $value);
                }
            }
        }

        return $this->cleanValues($values);
    }

    /**
     * @param  array<string, mixed>  $frame
     * @param  array<string, mixed>  $comments
     * @return list<string>
     */
    private function describedFrameValues(array $frame, array $comments, string $description): array
    {
        $values = $this->cleanValues([$frame['data'] ?? null]);
        if ($values !== []) {
            return $values;
        }

        $descriptionKey = $this->normalizedKey($description);
        if ($descriptionKey !== null) {
            $values = $this->cleanValues((array) ($comments[$descriptionKey] ?? []));
            if ($values !== []) {
                return $values;
            }
        }

        $shortName = is_string($frame['framenameshort'] ?? null)
            ? $frame['framenameshort']
            : null;
        $groupedValues = $shortName !== null && is_array($comments[$shortName] ?? null)
            ? $comments[$shortName]
            : [];

        foreach ($groupedValues as $name => $value) {
            if (is_string($name) && $this->normalizedKey($name) === $descriptionKey) {
                return $this->cleanValues((array) $value);
            }
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function cleanValues(array $values): array
    {
        $cleaned = [];
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $cleaned[] = trim((string) $value);
            }
        }

        return array_values(array_filter(
            array_unique($cleaned),
            static fn (string $value): bool => $value !== ''
                && ! str_starts_with($value, '[binary data omitted:'),
        ));
    }

    /** @param array<string, mixed> $frame */
    private function name(string $frameId, array $frame, string $description): string
    {
        if ($description !== '') {
            return $description;
        }

        $longName = trim((string) ($frame['framenamelong'] ?? ''));

        return $longName !== '' ? $longName : $frameId;
    }

    private function normalizedKey(string $value): ?string
    {
        $key = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($value)) ?? '', '_');

        return $key !== '' ? $key : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function isPlaybackStatistic(string $frameId, string $description): bool
    {
        return $frameId === 'PCNT'
            || in_array($this->normalizedKey($description), self::PLAYBACK_STATISTIC_NAMES, true);
    }
}
