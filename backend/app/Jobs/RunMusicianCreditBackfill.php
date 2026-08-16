<?php

namespace App\Jobs;

use App\Enums\OnlineContentStatus;
use App\Models\MusicianCreditBackfillRun;
use App\Music\Enrichment\AlbumMusicianCreditManager;
use App\Music\Enrichment\MusicianCreditBackfillManager;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunMusicianCreditBackfill implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(
        AlbumMusicianCreditManager $credits,
        MusicianCreditBackfillManager $backfills,
    ): void {
        $run = MusicianCreditBackfillRun::query()->find($this->runId);
        if ($run === null || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        if ($run->retry_after?->isFuture() ?? false) {
            self::dispatch($run->id)->delay($run->retry_after);

            return;
        }
        if ($run->lookup_version !== AlbumMusicianCreditManager::LOOKUP_VERSION) {
            $backfills->fail($run, 'This backfill belongs to an older musician lookup version.');

            return;
        }

        $run = $backfills->begin($run);
        if ($run->status !== 'running') {
            return;
        }

        $album = $backfills->nextAlbum($run);
        if ($album === null) {
            $backfills->complete($run);

            return;
        }

        $started = hrtime(true);
        $error = null;
        try {
            $outcome = $credits->refresh($album->id);
        } catch (Throwable $exception) {
            $outcome = OnlineContentStatus::Error;
            $error = $exception->getMessage();
        }
        if ($outcome === null) {
            $backfills->pauseForDisabledProvider($run);

            return;
        }

        $elapsedMilliseconds = max(1, (int) round((hrtime(true) - $started) / 1_000_000));
        $enrichment = $outcome === OnlineContentStatus::Error
            ? $album->musicianEnrichment()->first(['last_error_code', 'retry_after'])
            : null;
        if ($outcome === OnlineContentStatus::Error && $error === null) {
            $error = $enrichment?->last_error_code;
        }
        if ($outcome === OnlineContentStatus::Error && $error === 'rate_limited') {
            $retryAfter = $enrichment?->retry_after;
            if ($retryAfter !== null) {
                $run = $backfills->deferForRateLimit($run, $retryAfter);
                self::dispatch($run->id)->delay($run->retry_after);

                return;
            }
        }
        $run = $backfills->record($run, $album, $outcome, $elapsedMilliseconds, $error);
        if ($run->cancel_requested_at !== null || $run->status === 'cancelled') {
            return;
        }
        if ($run->pause_requested_at !== null) {
            $backfills->begin($run);

            return;
        }

        self::dispatch($run->id);
    }

    public function failed(?Throwable $exception): void
    {
        $run = MusicianCreditBackfillRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }
        if (in_array($run->status, ['completed', 'partial', 'cancelled'], true)) {
            return;
        }

        app(MusicianCreditBackfillManager::class)->fail(
            $run,
            $exception?->getMessage() ?? 'The musician backfill worker stopped unexpectedly.',
        );
    }
}
