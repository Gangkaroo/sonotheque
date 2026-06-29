<?php

namespace App\Jobs;

use App\Models\ApplicationSetting;
use App\Models\Track;
use App\Music\PlaybackStatistics\PlaybackStatisticsFileSynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SynchronizeTrackPlaybackStatistics implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $trackId) {}

    public function uniqueId(): string
    {
        return (string) $this->trackId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(PlaybackStatisticsFileSynchronizer $synchronizer): void
    {
        if (! ApplicationSetting::current()->synchronizesPlaybackStatisticsWithTags()) {
            return;
        }

        $track = Track::find($this->trackId);
        if ($track !== null) {
            $synchronizer->synchronize($track);
        }
    }
}
