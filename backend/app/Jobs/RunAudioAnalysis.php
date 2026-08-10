<?php

namespace App\Jobs;

use App\Models\AudioAnalysisArtifact;
use App\Models\AudioAnalysisRun;
use App\Models\AudioAnalysisRunItem;
use App\Models\ApplicationSetting;
use App\Music\Intelligence\AudioAnalyzer;
use App\Music\Intelligence\AudioAnalysisProfileSelector;
use App\Music\Intelligence\AudioVectorIndex;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class RunAudioAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly int $audioAnalysisRunId)
    {
        $this->onQueue('analysis');
    }

    public function handle(
        AudioAnalyzer $analyzer,
        AudioVectorIndex $vectorIndex,
        LibraryPathGuard $pathGuard,
        AudioAnalysisProfileSelector $profileSelector,
    ): void {
        $run = AudioAnalysisRun::with([
            'profile',
        ])->findOrFail($this->audioAnalysisRunId);
        $settings = ApplicationSetting::current();
        $accelerator = $settings->audioIntelligenceAccelerator();

        if ($run->phase !== 'analysis') {
            return;
        }
        if (in_array($run->status, ['completed', 'partial', 'failed', 'cancelled', 'paused'], true)) {
            $analyzer->shutdown();

            return;
        }
        if (! $settings->audio_intelligence_enabled) {
            $this->pause($run);
            $analyzer->shutdown();

            return;
        }
        if ($run->profile === null) {
            $analyzer->shutdown();

            throw new RuntimeException('The audio analysis run has no analyzer profile.');
        }

        $run->items()->where('status', 'running')->update([
            'status' => 'queued',
            'error' => null,
        ]);
        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'finished_at' => null,
            'heartbeat_at' => now(),
        ]);
        $chunkSize = max(1, min(25, (int) config('sonotheque.audio_intelligence.chunk_size', 5)));
        $items = $run->items()
            ->with('track.mediaFile.libraryRoot')
            ->whereIn('status', ['selected', 'queued'])
            ->lazyById($chunkSize);
        $lastProgressUpdate = microtime(true);

        try {
            foreach ($items->chunk($chunkSize) as $chunk) {
                $chunk = $chunk->collect();
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

                $run->update(['heartbeat_at' => now()]);
                $this->reuseAvailableArtifacts($run, $chunk);
                $chunk = $chunk->whereIn('status', ['selected', 'queued'])->values();
                $measuredTrackCount = $chunk->count();
                $requests = $this->prepareChunk($chunk, $pathGuard);
                if ($requests !== []) {
                    $analysisStarted = hrtime(true);
                    $results = $analyzer->analyzeBatch($requests);
                    $analysisElapsedMs = max(
                        1,
                        (int) round((hrtime(true) - $analysisStarted) / 1_000_000),
                    );
                    $this->persistChunkResults($run, $chunk, $results, $vectorIndex);
                    $this->recordAnalysisTiming(
                        $run,
                        $measuredTrackCount,
                        $analysisElapsedMs,
                        $accelerator,
                    );
                }
                $run->update(['heartbeat_at' => now()]);
                if (microtime(true) - $lastProgressUpdate >= 2) {
                    $this->updateProgress($run);
                    $lastProgressUpdate = microtime(true);
                }
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

            $this->finish($run);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 4000);
            $run->items()->whereIn('status', ['selected', 'queued', 'running'])->update([
                'status' => 'failed',
                'error' => $message,
            ]);
            $run->update([
                'status' => 'failed',
                'summary' => array_merge($run->summary ?? [], [
                    'analysisError' => $message,
                ]),
                'finished_at' => now(),
            ]);

            throw $exception;
        } finally {
            $profileSelector->forget();
            $analyzer->shutdown();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = AudioAnalysisRun::find($this->audioAnalysisRunId);
        if ($run === null || in_array(
            $run->status,
            ['completed', 'partial', 'failed', 'cancelled', 'paused'],
            true,
        )) {
            return;
        }

        $run->update([
            'status' => 'failed',
            'summary' => array_merge($run->summary ?? [], [
                'analysisError' => mb_substr(
                    $exception?->getMessage() ?? 'The audio analysis run failed.',
                    0,
                    4000,
                ),
            ]),
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, AudioAnalysisRunItem>  $chunk
     * @return list<array{
     *     itemId: int,
     *     path: string,
     *     durationSeconds: float|null,
     *     libraryRootPath: string,
     *     relativePath: string
     * }>
     */
    private function prepareChunk(Collection $chunk, LibraryPathGuard $pathGuard): array
    {
        $validItems = collect();
        foreach ($chunk as $item) {
            try {
                $mediaFile = $item->track?->mediaFile;
                $root = $mediaFile?->libraryRoot;
                if ($mediaFile === null
                    || $root === null
                    || $mediaFile->content_fingerprint !== $item->content_fingerprint
                    || $mediaFile->content_fingerprint_version !== $item->content_fingerprint_version) {
                    $item->update([
                        'status' => 'stale',
                        'error' => 'The audio content changed after the analysis run was prepared.',
                    ]);

                    continue;
                }

                $absolutePath = $pathGuard->resolveExistingFileWithin($root->path, $mediaFile->relative_path);
                if ($absolutePath === null) {
                    throw new InvalidLibraryPath('The sampled audio file no longer exists.');
                }

                $validItems->push([$item, $absolutePath]);
            } catch (Throwable $exception) {
                $item->update([
                    'status' => 'failed',
                    'error' => mb_substr($exception->getMessage(), 0, 4000),
                ]);
            }
        }

        $requests = [];
        foreach ($validItems->groupBy(
            fn (array $entry): string => $this->artifactKey($entry[0]),
        ) as $matchingEntries) {
            /** @var AudioAnalysisRunItem $representative */
            [$representative, $absolutePath] = $matchingEntries->first();
            foreach ($matchingEntries as [$item]) {
                $item->update(['status' => 'running', 'error' => null]);
            }
            $requests[] = [
                'itemId' => $representative->id,
                'path' => $absolutePath,
                'durationSeconds' => $representative->track?->duration_ms === null
                    ? null
                    : $representative->track->duration_ms / 1000,
                'libraryRootPath' => $representative->track->mediaFile->libraryRoot->path,
                'relativePath' => $representative->track->mediaFile->relative_path,
            ];
        }

        return $requests;
    }

    /**
     * @param  Collection<int, AudioAnalysisRunItem>  $chunk
     * @param  list<\App\Music\Intelligence\AudioAnalyzerResult>  $results
     */
    private function persistChunkResults(
        AudioAnalysisRun $run,
        Collection $chunk,
        array $results,
        AudioVectorIndex $vectorIndex,
    ): void {
        $items = $chunk->keyBy('id');
        $handled = [];

        foreach ($results as $result) {
            $item = $items->get($result->itemId);
            if ($item === null || $item->status !== 'running' || isset($handled[$item->id])) {
                continue;
            }
            $handled[$item->id] = true;

            $matchingItems = $chunk->filter(
                fn (AudioAnalysisRunItem $candidate): bool => $this->artifactKey($candidate)
                    === $this->artifactKey($item),
            );
            if ($result->status === 'failed') {
                $matchingItems->each->update([
                    'status' => 'failed',
                    'error' => mb_substr($result->error ?? 'The analyzer rejected this file.', 0, 4000),
                ]);

                continue;
            }
            if (count($result->embedding) !== $run->profile->embedding_dimensions) {
                $matchingItems->each->update([
                    'status' => 'failed',
                    'error' => 'The analyzer returned an embedding with unexpected dimensions.',
                ]);

                continue;
            }

            $artifact = AudioAnalysisArtifact::query()->firstOrCreate(
                [
                    'audio_analysis_profile_id' => $run->profile->id,
                    'content_fingerprint' => $item->content_fingerprint,
                    'content_fingerprint_version' => $item->content_fingerprint_version,
                ],
                [
                    'features' => $result->features,
                    'embedding' => $result->embedding,
                    'runtime_ms' => $result->runtimeMs,
                    'windows_analyzed' => $result->windowsAnalyzed,
                    'timings' => $result->timings,
                    'hardware' => $result->hardware,
                ],
            );
            $vectorIndex->synchronize($artifact, $result->embedding);
            foreach ($matchingItems as $matchingItem) {
                $matchingItem->update([
                    'audio_analysis_artifact_id' => $artifact->id,
                    'status' => $matchingItem->id === $item->id ? 'completed' : 'reused',
                    'error' => null,
                ]);
            }
        }

        $run->items()
            ->whereIn('id', $chunk->pluck('id'))
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'error' => 'The analyzer returned no result for this file.',
            ]);
    }

    private function cancel(AudioAnalysisRun $run): void
    {
        $run->items()->whereIn('status', ['selected', 'queued'])->update([
            'status' => 'cancelled',
            'error' => null,
        ]);
        $this->updateProgress($run);
        $run->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    private function pause(AudioAnalysisRun $run): void
    {
        $run->items()->where('status', 'running')->update([
            'status' => 'queued',
            'error' => null,
        ]);
        $this->updateProgress($run);
        $run->update([
            'status' => 'paused',
            'finished_at' => null,
            'heartbeat_at' => now(),
        ]);
    }

    private function finish(AudioAnalysisRun $run): void
    {
        $summary = $this->progressSummary($run);
        $completed = $summary['analyzedTrackCount'];
        $successful = $completed + $summary['reusedTrackCount'];

        $run->update([
            'status' => match (true) {
                $successful === $run->selected_track_count => 'completed',
                $successful > 0 => 'partial',
                default => 'failed',
            },
            'summary' => array_merge($run->summary ?? [], $summary),
            'finished_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    private function updateProgress(AudioAnalysisRun $run): void
    {
        $run->update([
            'summary' => array_merge($run->summary ?? [], $this->progressSummary($run)),
        ]);
    }

    private function recordAnalysisTiming(
        AudioAnalysisRun $run,
        int $trackCount,
        int $elapsedMs,
        string $accelerator,
    ): void {
        $summary = $run->summary ?? [];
        $configuredAccelerator = strtolower((string) config(
            'sonotheque.audio_intelligence.accelerator',
            'cpu',
        ));
        $imageKey = $accelerator === $configuredAccelerator
            ? 'sonotheque.audio_intelligence.docker_image'
            : ($accelerator === 'cuda'
                ? 'sonotheque.audio_intelligence.benchmark_cuda_image'
                : 'sonotheque.audio_intelligence.benchmark_cpu_image');
        $performanceKey = hash('sha256', json_encode([
            'driver' => config('sonotheque.audio_intelligence.driver'),
            'image' => config($imageKey),
            'accelerator' => $accelerator,
            'persistent' => config('sonotheque.audio_intelligence.persistent'),
            'cpuLimit' => config('sonotheque.audio_intelligence.cpu_limit'),
            'memoryLimit' => config('sonotheque.audio_intelligence.memory_limit'),
            'preparationWorkers' => config(
                'sonotheque.audio_intelligence.preparation_workers',
            ),
            'chunkSize' => config('sonotheque.audio_intelligence.chunk_size'),
        ], JSON_THROW_ON_ERROR));
        $samples = ($summary['analysisPerformanceKey'] ?? null) === $performanceKey
            && is_array($summary['analysisTimingSamples'] ?? null)
                ? $summary['analysisTimingSamples']
                : [];
        $samples[] = [
            'trackCount' => max(1, $trackCount),
            'elapsedMs' => max(1, $elapsedMs),
        ];
        $samples = array_slice($samples, -20);

        $summary['analysisPerformanceKey'] = $performanceKey;
        $summary['analysisTimingSamples'] = $samples;
        $summary['analysisMeasuredTrackCount'] = array_sum(array_column($samples, 'trackCount'));
        $summary['analysisElapsedMs'] = array_sum(array_column($samples, 'elapsedMs'));
        $run->update(['summary' => $summary]);
    }

    /**
     * @return array{
     *     analyzedTrackCount: int,
     *     reusedTrackCount: int,
     *     failedTrackCount: int,
     *     staleTrackCount: int,
     *     cancelledTrackCount: int,
     *     processedTrackCount: int,
     *     runtimeMs: int
     * }
     */
    private function progressSummary(AudioAnalysisRun $run): array
    {
        $counts = $run->items()
            ->selectRaw('status, COUNT(*)::int AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $completed = (int) ($counts['completed'] ?? 0);
        $reused = (int) ($counts['reused'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $stale = (int) ($counts['stale'] ?? 0);
        $cancelled = (int) ($counts['cancelled'] ?? 0);

        return [
            'analyzedTrackCount' => $completed,
            'reusedTrackCount' => $reused,
            'failedTrackCount' => $failed,
            'staleTrackCount' => $stale,
            'cancelledTrackCount' => $cancelled,
            'processedTrackCount' => $completed + $reused + $failed + $stale + $cancelled,
            'runtimeMs' => (int) AudioAnalysisArtifact::query()
                ->whereHas(
                    'runItems',
                    fn ($query) => $query
                        ->where('audio_analysis_run_id', $run->id)
                        ->where('status', 'completed'),
                )
                ->sum('runtime_ms'),
        ];
    }

    /**
     * @param  Collection<int, AudioAnalysisRunItem>  $items
     */
    private function reuseAvailableArtifacts(AudioAnalysisRun $run, Collection $items): void
    {
        $pending = $items->whereIn('status', ['selected', 'queued']);
        if ($pending->isEmpty()) {
            return;
        }

        $artifacts = AudioAnalysisArtifact::query()
            ->where('audio_analysis_profile_id', $run->audio_analysis_profile_id)
            ->whereIn('content_fingerprint', $pending->pluck('content_fingerprint')->unique())
            ->get()
            ->keyBy(
                fn (AudioAnalysisArtifact $artifact): string => $artifact->content_fingerprint_version
                    .':'.$artifact->content_fingerprint,
            );

        foreach ($pending as $item) {
            $artifact = $artifacts->get($this->artifactKey($item));
            if ($artifact === null) {
                continue;
            }

            $item->update([
                'audio_analysis_artifact_id' => $artifact->id,
                'status' => 'reused',
                'error' => null,
            ]);
        }
    }

    private function artifactKey(AudioAnalysisRunItem $item): string
    {
        return $item->content_fingerprint_version.':'.$item->content_fingerprint;
    }
}
