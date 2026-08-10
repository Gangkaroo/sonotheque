<?php

namespace App\Jobs;

use App\Models\AudioAnalysisRun;
use App\Models\AudioAnalysisRunItem;
use App\Models\ApplicationSetting;
use App\Music\Intelligence\AudioAnalysisRunPlanner;
use App\Music\Scanning\AudioContentFingerprinter;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PrepareAudioAnalysisRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly int $audioAnalysisRunId)
    {
        $this->onQueue('analysis');
    }

    public function handle(
        AudioContentFingerprinter $fingerprinter,
        LibraryPathGuard $pathGuard,
        AudioAnalysisRunPlanner $planner,
    ): void {
        $run = AudioAnalysisRun::findOrFail($this->audioAnalysisRunId);
        if ($run->phase !== 'preparation'
            || in_array(
                $run->status,
                ['prepared', 'completed', 'failed', 'cancelled', 'paused'],
                true,
            )) {
            return;
        }
        if (! ApplicationSetting::current()->audio_intelligence_enabled) {
            $this->pause($run);

            return;
        }

        $run->items()->where('status', 'fingerprinting')->update([
            'status' => 'pending_fingerprint',
            'error' => null,
        ]);
        $run->update([
            'status' => 'fingerprinting',
            'started_at' => $run->started_at ?? now(),
            'finished_at' => null,
            'heartbeat_at' => now(),
        ]);

        try {
            if ($run->kind === 'collection'
                && ! ($run->summary['candidatesEnumerated'] ?? false)
                && ! $planner->populateCollectionRun($run)) {
                $run->refresh();
                if ($run->cancel_requested_at !== null) {
                    $this->cancel($run);
                } else {
                    $this->pause($run);
                }

                return;
            }

            $items = $run->items()
                ->with('track.mediaFile.libraryRoot')
                ->whereIn('status', ['pending_fingerprint', 'fingerprint_failed', 'cancelled'])
                ->lazyById(
                    max(
                        1,
                        min(
                            50,
                            (int) config(
                                'sonotheque.audio_intelligence.preparation_chunk_size',
                                10,
                            ),
                        ),
                    ),
                );
            $chunkSize = max(
                1,
                min(50, (int) config('sonotheque.audio_intelligence.preparation_chunk_size', 10)),
            );
            $selectedCount = $run->items()
                ->whereIn('status', ['selected', 'reused'])
                ->count();

            foreach ($items->chunk($chunkSize) as $chunk) {
                foreach ($chunk as $item) {
                    $run->refresh();
                    if ($run->cancel_requested_at !== null) {
                        $this->cancel($run);

                        return;
                    }
                    if ($run->pause_requested_at !== null) {
                        $this->pause($run);

                        return;
                    }
                    if (! ApplicationSetting::current()->audio_intelligence_enabled) {
                        $this->pause($run);

                        return;
                    }
                    if ($selectedCount >= $run->requested_track_count) {
                        $run->items()
                            ->whereIn('status', ['pending_fingerprint', 'cancelled'])
                            ->update(['status' => 'not_selected', 'error' => null]);
                        $this->finish($run, $planner);

                        return;
                    }

                    if ($this->fingerprintItem($item, $fingerprinter, $pathGuard)) {
                        $selectedCount++;
                    }
                }

                $this->updateProgress($run);
                $run->update(['heartbeat_at' => now()]);
            }

            $run->refresh();
            if ($run->cancel_requested_at !== null) {
                $this->cancel($run);

                return;
            }
            if ($run->pause_requested_at !== null) {
                $this->pause($run);

                return;
            }

            $this->finish($run, $planner);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 4000);
            $run->update([
                'status' => 'failed',
                'summary' => array_merge($run->summary ?? [], [
                    'fingerprintPreparationError' => $message,
                ]),
                'finished_at' => now(),
                'heartbeat_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function fingerprintItem(
        AudioAnalysisRunItem $item,
        AudioContentFingerprinter $fingerprinter,
        LibraryPathGuard $pathGuard,
    ): bool {
        try {
            $mediaFile = $item->track?->mediaFile;
            $root = $mediaFile?->libraryRoot;
            if ($mediaFile === null || $root === null) {
                throw new RuntimeException('The selected track no longer has an available media file.');
            }

            if ($mediaFile->content_fingerprint !== null
                && $mediaFile->content_fingerprint_version === AudioContentFingerprinter::VERSION) {
                $item->update([
                    'content_fingerprint' => $mediaFile->content_fingerprint,
                    'content_fingerprint_version' => $mediaFile->content_fingerprint_version,
                    'status' => 'selected',
                    'error' => null,
                ]);

                return true;
            }

            $item->update(['status' => 'fingerprinting', 'error' => null]);
            $absolutePath = $pathGuard->resolveExistingFileWithin(
                $root->path,
                $mediaFile->relative_path,
            );
            if ($absolutePath === null) {
                throw new RuntimeException('The selected audio file no longer exists.');
            }
            $beforeSize = filesize($absolutePath);
            $beforeModifiedAt = filemtime($absolutePath);
            if ($beforeSize === false
                || $beforeModifiedAt === false
                || $beforeSize !== $mediaFile->file_size
                || $beforeModifiedAt !== $mediaFile->modified_at?->getTimestamp()) {
                throw new RuntimeException('The selected audio file changed after its last library scan.');
            }

            $fingerprint = $fingerprinter->fingerprint($absolutePath);
            clearstatcache(true, $absolutePath);
            if (filesize($absolutePath) !== $beforeSize || filemtime($absolutePath) !== $beforeModifiedAt) {
                throw new RuntimeException('The selected audio file changed while it was fingerprinted.');
            }

            DB::transaction(function () use ($item, $mediaFile, $fingerprint): void {
                $mediaFile->update([
                    'content_fingerprint' => $fingerprint,
                    'content_fingerprint_version' => AudioContentFingerprinter::VERSION,
                ]);
                $item->update([
                    'content_fingerprint' => $fingerprint,
                    'content_fingerprint_version' => AudioContentFingerprinter::VERSION,
                    'status' => 'selected',
                    'error' => null,
                ]);
            });

            return true;
        } catch (Throwable $exception) {
            $item->update([
                'status' => 'fingerprint_failed',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
            ]);

            return false;
        }
    }

    private function finish(
        AudioAnalysisRun $run,
        AudioAnalysisRunPlanner $planner,
    ): void {
        $summary = $this->progressSummary($run);
        $selected = $summary['fingerprintedTrackCount'];
        $run->update([
            'status' => $selected > 0 ? 'prepared' : 'failed',
            'selected_track_count' => $selected,
            'summary' => array_merge($run->summary ?? [], $summary),
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
        $planner->forgetCoverage();
    }

    private function cancel(AudioAnalysisRun $run): void
    {
        $run->items()
            ->whereIn('status', ['pending_fingerprint', 'fingerprinting', 'fingerprint_failed'])
            ->update(['status' => 'cancelled', 'error' => null]);
        $this->updateProgress($run);
        $run->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    private function pause(AudioAnalysisRun $run): void
    {
        $run->items()
            ->where('status', 'fingerprinting')
            ->update(['status' => 'pending_fingerprint', 'error' => null]);
        $this->updateProgress($run);
        $run->update([
            'status' => 'paused',
            'finished_at' => null,
            'heartbeat_at' => now(),
        ]);
    }

    private function updateProgress(AudioAnalysisRun $run): void
    {
        $summary = $this->progressSummary($run);
        $run->update([
            'selected_track_count' => $summary['fingerprintedTrackCount'],
            'summary' => array_merge($run->summary ?? [], $summary),
        ]);
    }

    /**
     * @return array{
     *     fingerprintedTrackCount: int,
     *     reusedTrackCount: int,
     *     fingerprintFailedTrackCount: int,
     *     processedFingerprintTrackCount: int,
     *     selectedRootCount: int,
     *     selectedGenreCount: int,
     *     selectedArtistCount: int,
     *     unclassifiedTrackCount: int
     * }
     */
    private function progressSummary(AudioAnalysisRun $run): array
    {
        $selectedQuery = DB::table('audio_analysis_run_items')
            ->where('audio_analysis_run_id', $run->id)
            ->whereIn('status', ['selected', 'reused']);
        $selected = (clone $selectedQuery)->count();
        $reused = $run->items()->where('status', 'reused')->count();
        $failed = $run->items()->where('status', 'fingerprint_failed')->count();

        return [
            'fingerprintedTrackCount' => $selected,
            'reusedTrackCount' => $reused,
            'fingerprintFailedTrackCount' => $failed,
            'processedFingerprintTrackCount' => $selected + $failed,
            'selectedRootCount' => (clone $selectedQuery)
                ->whereNotNull('library_root_id')
                ->distinct('library_root_id')
                ->count('library_root_id'),
            'selectedGenreCount' => (clone $selectedQuery)
                ->whereNotNull('genre_id')
                ->distinct('genre_id')
                ->count('genre_id'),
            'selectedArtistCount' => DB::table('audio_analysis_run_items as items')
                ->join('tracks', 'tracks.id', '=', 'items.track_id')
                ->join('albums', 'albums.id', '=', 'tracks.album_id')
                ->leftJoin('artist_track', 'artist_track.track_id', '=', 'tracks.id')
                ->where('items.audio_analysis_run_id', $run->id)
                ->where('items.status', 'selected')
                ->distinct()
                ->count(DB::raw('COALESCE(artist_track.artist_id, albums.primary_artist_id)')),
            'unclassifiedTrackCount' => (clone $selectedQuery)->whereNull('genre_id')->count(),
        ];
    }
}
