<?php

namespace App\Music\Enrichment;

use App\Enums\OnlineContentStatus;
use App\Jobs\RunMusicianCreditBackfill;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\LibraryRoot;
use App\Models\MusicianCreditBackfillRun;
use App\Support\LibraryRootScope;
use App\Support\MusicianCatalog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MusicianCreditBackfillManager
{
    /** @var list<string> */
    private const ACTIVE_STATUSES = ['queued', 'running', 'paused'];

    /** @var list<string> */
    private const COMPLETED_ENRICHMENT_STATUSES = [
        OnlineContentStatus::Ready->value,
        OnlineContentStatus::NotFound->value,
        OnlineContentStatus::Ambiguous->value,
    ];

    public function __construct(
        private readonly LibraryRootScope $libraryRootScope,
        private readonly MusicianCatalog $catalog,
    ) {
    }

    public function start(?LibraryRoot $libraryRoot): MusicianCreditBackfillRun
    {
        if (! ApplicationSetting::current()->online_information_enabled) {
            throw new ConflictHttpException(
                'Enable online information before starting the musician backfill.',
            );
        }

        $run = DB::transaction(function () use ($libraryRoot): MusicianCreditBackfillRun {
            if (MusicianCreditBackfillRun::query()
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->exists()) {
                throw new ConflictHttpException(
                    'Finish or cancel the active musician backfill before starting another one.',
                );
            }

            $maximum = $this->scopedAlbums($libraryRoot?->id)->max('albums.id');
            $maxAlbumId = $maximum === null ? null : (int) $maximum;
            $eligibleAlbums = $this->eligibleAlbums($libraryRoot?->id);
            if ($maxAlbumId !== null) {
                $eligibleAlbums->where('albums.id', '<=', $maxAlbumId);
            }
            $total = $eligibleAlbums->count();

            return MusicianCreditBackfillRun::query()->create([
                'library_root_id' => $libraryRoot?->id,
                'lookup_version' => AlbumMusicianCreditManager::LOOKUP_VERSION,
                'status' => $total === 0 ? 'completed' : 'queued',
                'max_album_id' => $maxAlbumId,
                'total_album_count' => $total,
                'finished_at' => $total === 0 ? now() : null,
            ]);
        });

        if ($run->status === 'queued') {
            RunMusicianCreditBackfill::dispatch($run->id);
        }

        return $run;
    }

    public function pause(MusicianCreditBackfillRun $run): MusicianCreditBackfillRun
    {
        if (! in_array($run->status, ['queued', 'running'], true)) {
            throw new ConflictHttpException('Only an active musician backfill can be paused.');
        }

        $waitingForProvider = $run->retry_after?->isFuture() ?? false;
        $run->update([
            'status' => $waitingForProvider ? 'paused' : $run->status,
            'pause_requested_at' => now(),
            'retry_after' => $waitingForProvider ? null : $run->retry_after,
            'heartbeat_at' => $waitingForProvider ? now() : $run->heartbeat_at,
        ]);

        return $run->fresh();
    }

    public function resume(MusicianCreditBackfillRun $run): MusicianCreditBackfillRun
    {
        if (! ApplicationSetting::current()->online_information_enabled) {
            throw new ConflictHttpException(
                'Enable online information before resuming the musician backfill.',
            );
        }
        if (! $this->canResume($run)) {
            throw new ConflictHttpException(
                'This musician backfill is complete or still has an active worker.',
            );
        }
        if (MusicianCreditBackfillRun::query()
            ->whereKeyNot($run->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists()) {
            throw new ConflictHttpException(
                'Finish or cancel the active musician backfill before resuming this one.',
            );
        }

        $run->update([
            'status' => 'queued',
            'finished_at' => null,
            'pause_requested_at' => null,
            'cancel_requested_at' => null,
            'heartbeat_at' => null,
            'last_error' => null,
            'retry_after' => null,
        ]);
        RunMusicianCreditBackfill::dispatch($run->id);

        return $run->fresh();
    }

    public function cancel(MusicianCreditBackfillRun $run): MusicianCreditBackfillRun
    {
        if (! in_array($run->status, self::ACTIVE_STATUSES, true)) {
            throw new ConflictHttpException('Only an active musician backfill can be cancelled.');
        }

        $run->update([
            'status' => 'cancelled',
            'cancel_requested_at' => now(),
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'retry_after' => null,
        ]);

        return $run->fresh();
    }

    public function nextAlbum(MusicianCreditBackfillRun $run): ?Album
    {
        return $this->eligibleAlbums($run->library_root_id)
            ->where('albums.id', '>', $run->last_album_id ?? 0)
            ->when(
                $run->max_album_id,
                fn (Builder $albums, int $id) => $albums->where('albums.id', '<=', $id),
            )
            ->orderBy('albums.id')
            ->first();
    }

    public function begin(MusicianCreditBackfillRun $run): MusicianCreditBackfillRun
    {
        if ($run->cancel_requested_at !== null || $run->status === 'cancelled') {
            return $run;
        }
        if ($run->pause_requested_at !== null) {
            $run->update([
                'status' => 'paused',
                'heartbeat_at' => now(),
            ]);

            return $run->fresh();
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'heartbeat_at' => now(),
            'retry_after' => null,
        ]);

        return $run->fresh();
    }

    public function record(
        MusicianCreditBackfillRun $run,
        Album $album,
        OnlineContentStatus $outcome,
        int $elapsedMilliseconds,
        ?string $error = null,
    ): MusicianCreditBackfillRun {
        DB::transaction(function () use ($run, $album, $outcome, $elapsedMilliseconds, $error): void {
            $locked = MusicianCreditBackfillRun::query()->lockForUpdate()->findOrFail($run->id);
            if (($locked->last_album_id ?? 0) >= $album->id || $locked->status === 'cancelled') {
                return;
            }

            $counter = match ($outcome) {
                OnlineContentStatus::Ready => 'ready_album_count',
                OnlineContentStatus::NotFound => 'not_found_album_count',
                OnlineContentStatus::Ambiguous => 'ambiguous_album_count',
                default => 'failed_album_count',
            };
            $locked->increment('processed_album_count');
            $locked->increment($counter);
            $locked->increment('processing_milliseconds', max(1, $elapsedMilliseconds));
            $values = [
                'last_album_id' => $album->id,
                'heartbeat_at' => now(),
            ];
            if ($error !== null) {
                $values['last_error'] = mb_substr($error, 0, 2000);
            }
            $locked->update($values);
        });

        return $run->fresh();
    }

    public function complete(MusicianCreditBackfillRun $run): MusicianCreditBackfillRun
    {
        $run->update([
            'status' => $run->failed_album_count > 0 ? 'partial' : 'completed',
            'total_album_count' => $run->processed_album_count,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'retry_after' => null,
        ]);

        return $run->fresh();
    }

    public function fail(MusicianCreditBackfillRun $run, string $message): void
    {
        $run->update([
            'status' => 'failed',
            'last_error' => mb_substr($message, 0, 2000),
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'retry_after' => null,
        ]);
    }

    public function pauseForDisabledProvider(MusicianCreditBackfillRun $run): void
    {
        $run->update([
            'status' => 'paused',
            'pause_requested_at' => now(),
            'last_error' => 'Online information was disabled while the backfill was running.',
            'heartbeat_at' => now(),
            'retry_after' => null,
        ]);
    }

    public function deferForRateLimit(
        MusicianCreditBackfillRun $run,
        CarbonInterface $retryAfter,
    ): MusicianCreditBackfillRun {
        $availableAt = $retryAfter->isFuture() ? $retryAfter : now()->addMinute();
        $run->update([
            'status' => 'queued',
            'last_error' => 'MusicBrainz rate limit reached; the same album will be retried.',
            'retry_after' => $availableAt,
            'heartbeat_at' => now(),
        ]);

        return $run->fresh();
    }

    /** @return array<string, mixed> */
    public function payload(?int $libraryRootId): array
    {
        $run = MusicianCreditBackfillRun::query()
            ->where('library_root_id', $libraryRootId)
            ->latest('id')
            ->first();
        $activeRun = MusicianCreditBackfillRun::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        return [
            'coverage' => $this->catalog->coverage($libraryRootId),
            'run' => $run === null ? null : $this->runPayload($run),
            'activeRun' => $activeRun === null ? null : $this->runPayload($activeRun),
        ];
    }

    public function canResume(MusicianCreditBackfillRun $run): bool
    {
        if ($run->lookup_version !== AlbumMusicianCreditManager::LOOKUP_VERSION) {
            return false;
        }
        if (in_array($run->status, ['paused', 'failed'], true)) {
            return true;
        }
        if ($run->retry_after?->isFuture() ?? false) {
            return false;
        }
        if (! in_array($run->status, ['queued', 'running'], true)) {
            return false;
        }

        $lastActivity = $run->heartbeat_at ?? $run->updated_at;

        return $lastActivity !== null && $lastActivity->lte(now()->subMinutes(5));
    }

    /** @return Builder<Album> */
    private function scopedAlbums(?int $libraryRootId): Builder
    {
        return $this->libraryRootScope
            ->albums(Album::query(), $libraryRootId)
            ->has('tracks');
    }

    /** @return Builder<Album> */
    private function eligibleAlbums(?int $libraryRootId): Builder
    {
        return $this->scopedAlbums($libraryRootId)
            ->whereDoesntHave('musicianReviews', fn (Builder $reviews) => $reviews
                ->where('lookup_version', AlbumMusicianCreditManager::LOOKUP_VERSION))
            ->whereDoesntHave('musicianEnrichment', fn (Builder $enrichments) => $enrichments
                ->where('lookup_version', AlbumMusicianCreditManager::LOOKUP_VERSION)
                ->whereIn('status', self::COMPLETED_ENRICHMENT_STATUSES));
    }

    /** @return array<string, mixed> */
    private function runPayload(MusicianCreditBackfillRun $run): array
    {
        $run->loadMissing('libraryRoot');
        $remaining = max(0, $run->total_album_count - $run->processed_album_count);
        $estimatedRemainingMilliseconds = $run->processed_album_count === 0
            ? null
            : (int) round(
                ($run->processing_milliseconds / $run->processed_album_count) * $remaining,
            );

        return [
            'id' => $run->id,
            'status' => $run->status,
            'lookupVersion' => $run->lookup_version,
            'libraryRoot' => $run->libraryRoot === null ? null : [
                'id' => $run->libraryRoot->id,
                'name' => $run->libraryRoot->name,
            ],
            'totalAlbumCount' => $run->total_album_count,
            'processedAlbumCount' => $run->processed_album_count,
            'readyAlbumCount' => $run->ready_album_count,
            'notFoundAlbumCount' => $run->not_found_album_count,
            'ambiguousAlbumCount' => $run->ambiguous_album_count,
            'failedAlbumCount' => $run->failed_album_count,
            'estimatedRemainingMilliseconds' => $estimatedRemainingMilliseconds,
            'lastError' => $run->last_error,
            'retryAfter' => $run->retry_after?->toAtomString(),
            'resumable' => $this->canResume($run),
            'pauseRequested' => $run->pause_requested_at !== null && $run->status !== 'paused',
            'startedAt' => $run->started_at?->toAtomString(),
            'finishedAt' => $run->finished_at?->toAtomString(),
            'createdAt' => $run->created_at?->toAtomString(),
        ];
    }
}
