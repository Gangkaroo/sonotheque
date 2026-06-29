<?php

namespace App\Music\PlaybackStatistics;

use Carbon\CarbonInterface;

interface PlaybackStatisticsTagWriter
{
    public function supports(string $path): bool;

    public function write(
        string $path,
        int $playCount,
        ?CarbonInterface $firstPlayedAt,
        ?CarbonInterface $lastPlayedAt,
    ): void;
}
