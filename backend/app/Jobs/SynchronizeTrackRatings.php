<?php

namespace App\Jobs;

use App\Models\ApplicationSetting;
use App\Models\Track;
use App\Music\Ratings\RatingFileSynchronizer;
use App\Music\Streaming\TrackStreamActivity;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SynchronizeTrackRatings implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $trackId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->trackId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        RatingFileSynchronizer $synchronizer,
        TrackStreamActivity $streamActivity,
    ): void {
        if (! ApplicationSetting::current()->synchronizesRatingsWithTags()) {
            return;
        }

        $retryAfterSeconds = $streamActivity->retryAfterSeconds($this->trackId);
        if ($retryAfterSeconds > 0) {
            self::dispatch($this->trackId)->delay(now()->addSeconds($retryAfterSeconds + 1));

            return;
        }

        $track = Track::find($this->trackId);
        if ($track !== null) {
            $synchronizer->synchronize($track);
        }
    }
}
