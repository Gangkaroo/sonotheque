<?php

namespace App\Music\PlaybackStatistics;

use App\Music\Metadata\Mp3Id3v2TagEditor;
use Carbon\CarbonInterface;
use RuntimeException;

class Mp3PlaybackStatisticsTagWriter implements PlaybackStatisticsTagWriter
{
    private const FILETIME_UNIX_EPOCH_SECONDS = 11_644_473_600;

    private const FILETIME_TICKS_PER_SECOND = 10_000_000;

    public function __construct(
        private readonly Mp3Id3v2TagEditor $editor,
        private readonly PlaybackStatisticsTagReader $reader,
    ) {}

    public function supports(string $path): bool
    {
        return $this->editor->supports($path);
    }

    public function write(
        string $path,
        int $playCount,
        ?CarbonInterface $firstPlayedAt,
        ?CarbonInterface $lastPlayedAt,
    ): void {
        $values = [
            'PLAY_COUNT' => (string) max(0, $playCount),
            'FIRST_PLAYED_TIMESTAMP' => $this->fileTime($firstPlayedAt),
            'LAST_PLAYED_TIMESTAMP' => $this->fileTime($lastPlayedAt),
        ];

        $this->editor->write($path, [], $values, function (string $temporaryPath) use (
            $playCount,
            $firstPlayedAt,
            $lastPlayedAt,
        ): void {
            $information = (new \getID3)->analyze($temporaryPath);
            \getid3_lib::CopyTagsToComments($information);
            $statistics = $this->reader->read($information);
            if ($statistics->playCount !== max(0, $playCount)
                || ! $this->sameInstant($statistics->firstPlayedAt, $firstPlayedAt)
                || ! $this->sameInstant($statistics->lastPlayedAt, $lastPlayedAt)) {
                throw new RuntimeException('Playback-statistics tags could not be verified after writing.');
            }
        });
    }

    private function fileTime(?CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return (string) ((($date->getTimestamp() + self::FILETIME_UNIX_EPOCH_SECONDS) * self::FILETIME_TICKS_PER_SECOND)
            + ((int) $date->format('u') * 10));
    }

    private function sameInstant(?CarbonInterface $left, ?CarbonInterface $right): bool
    {
        return $left === null || $right === null
            ? $left === null && $right === null
            : $left->toJSON() === $right->toImmutable()->utc()->toJSON();
    }
}
