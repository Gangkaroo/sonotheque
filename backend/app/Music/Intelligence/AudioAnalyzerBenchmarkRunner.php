<?php

namespace App\Music\Intelligence;

use App\Enums\MediaFileStatus;
use App\Models\AudioAnalyzerBenchmark;
use App\Models\Track;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class AudioAnalyzerBenchmarkRunner
{
    private const SAMPLE_CANDIDATE_LIMIT = 100;

    public function __construct(
        private readonly AudioBenchmarkAnalyzerFactory $analyzerFactory,
        private readonly LibraryPathGuard $pathGuard,
    ) {
    }

    public function run(AudioAnalyzerBenchmark $benchmark): void
    {
        $benchmark->update([
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'error' => null,
        ]);

        try {
            $requests = $this->sampleRequests($benchmark->sample_size);
            $benchmark->update([
                'sample_track_ids' => array_column($requests, 'itemId'),
            ]);

            $results = [];
            $references = null;
            $health = [];
            $initialConfigurations = [
                $this->configuration('cpu', 1, 5),
                $this->configuration('cuda', 1, 5),
                $this->configuration('cuda', 2, 5),
                $this->configuration('cuda', 3, 5),
            ];

            foreach ($initialConfigurations as $configuration) {
                if ($this->cancelIfRequested($benchmark)) {
                    return;
                }

                [$result, $configurationResults] = $this->benchmarkConfiguration(
                    $benchmark,
                    $configuration,
                    $requests,
                    $health,
                    $references,
                );
                $results[] = $result;
                if ($references === null && $configurationResults !== null) {
                    $references = $configurationResults;
                }
                $this->storeProgress($benchmark, $results);
            }

            $winner = $this->fastestEquivalent($results);
            $tuningAccelerator = $winner['accelerator'] ?? 'cpu';
            $tuningWorkers = (int) ($winner['preparationWorkers'] ?? 1);
            foreach ([10, 15] as $chunkSize) {
                if ($this->cancelIfRequested($benchmark)) {
                    return;
                }

                [$result] = $this->benchmarkConfiguration(
                    $benchmark,
                    $this->configuration(
                        $tuningAccelerator,
                        $tuningWorkers,
                        $chunkSize,
                    ),
                    $requests,
                    $health,
                    $references,
                );
                $results[] = $result;
                $this->storeProgress($benchmark, $results);
            }

            $recommendation = $this->fastestEquivalent($results);
            $completed = collect($results)->where('status', 'completed')->count();
            $benchmark->update([
                'status' => $completed === count($results) ? 'completed' : 'partial',
                'results' => $results,
                'recommendation' => $recommendation,
                'completed_configuration_count' => count($results),
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $benchmark->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return list<array{
     *     itemId: int,
     *     path: string,
     *     durationSeconds: float|null,
     *     libraryRootPath: string,
     *     relativePath: string
     * }>
     */
    private function sampleRequests(int $sampleSize): array
    {
        $maximumTrackId = (int) Track::query()->max('id');
        if ($maximumTrackId === 0) {
            throw new RuntimeException('No tracks are available for benchmarking.');
        }

        $startId = random_int(1, $maximumTrackId);
        $candidateQuery = Track::query()
            ->whereHas(
                'mediaFile',
                fn ($query) => $query->where('status', MediaFileStatus::Available->value),
            )
            ->whereHas(
                'mediaFile.libraryRoot',
                fn ($query) => $query->where('enabled', true),
            )
            ->with('mediaFile.libraryRoot');
        $tracks = (clone $candidateQuery)
            ->where('tracks.id', '>=', $startId)
            ->orderBy('tracks.id')
            ->limit(self::SAMPLE_CANDIDATE_LIMIT)
            ->get();
        $remainingCandidateCount = self::SAMPLE_CANDIDATE_LIMIT - $tracks->count();
        if ($remainingCandidateCount > 0) {
            $tracks->push(...(clone $candidateQuery)
                ->where('tracks.id', '<', $startId)
                ->orderBy('tracks.id')
                ->limit($remainingCandidateCount)
                ->get());
        }
        $requests = [];

        foreach ($tracks as $track) {
            $mediaFile = $track->mediaFile;
            $root = $mediaFile?->libraryRoot;
            if ($mediaFile === null || $root === null) {
                continue;
            }

            try {
                $path = $this->pathGuard->resolveExistingFileWithin(
                    $root->path,
                    $mediaFile->relative_path,
                );
            } catch (Throwable) {
                continue;
            }
            if ($path === null) {
                continue;
            }

            $requests[] = [
                'itemId' => $track->id,
                'path' => $path,
                'durationSeconds' => $track->duration_ms === null
                    ? null
                    : $track->duration_ms / 1000,
                'libraryRootPath' => $root->path,
                'relativePath' => $mediaFile->relative_path,
            ];
            if (count($requests) === $sampleSize) {
                break;
            }
        }

        if (count($requests) < $sampleSize) {
            throw new RuntimeException(
                "Only ".count($requests)." readable tracks were available for the {$sampleSize}-track benchmark.",
            );
        }

        return $requests;
    }

    /** @return array{accelerator: string, preparationWorkers: int, chunkSize: int} */
    private function configuration(
        string $accelerator,
        int $preparationWorkers,
        int $chunkSize,
    ): array {
        return [
            'accelerator' => $accelerator,
            'preparationWorkers' => $preparationWorkers,
            'chunkSize' => $chunkSize,
        ];
    }

    /**
     * @param  array{accelerator: string, preparationWorkers: int, chunkSize: int}  $configuration
     * @param  list<array<string, mixed>>  $requests
     * @param  array<string, AudioAnalyzerHealth>  $health
     * @param  list<AudioAnalyzerResult>|null  $references
     * @return array{array<string, mixed>, list<AudioAnalyzerResult>|null}
     */
    private function benchmarkConfiguration(
        AudioAnalyzerBenchmark $benchmark,
        array $configuration,
        array $requests,
        array &$health,
        ?array $references,
    ): array {
        $accelerator = $configuration['accelerator'];
        if (! isset($health[$accelerator])) {
            $probe = $this->analyzerFactory->create(
                $benchmark->id,
                $accelerator,
                $configuration['preparationWorkers'],
                $configuration['chunkSize'],
            );
            try {
                $health[$accelerator] = $probe->health();
            } finally {
                $probe->shutdown();
            }
        }
        if (! $health[$accelerator]->ready()) {
            return [[
                ...$configuration,
                'status' => 'unavailable',
                'error' => $health[$accelerator]->message,
            ], null];
        }

        $analyzer = $this->analyzerFactory->create(
            $benchmark->id,
            $accelerator,
            $configuration['preparationWorkers'],
            $configuration['chunkSize'],
        );
        $configurationResults = [];
        $started = hrtime(true);

        try {
            foreach (array_chunk($requests, $configuration['chunkSize']) as $chunk) {
                if ($benchmark->fresh()->cancel_requested_at !== null) {
                    return [[
                        ...$configuration,
                        'status' => 'cancelled',
                    ], null];
                }
                $chunkResults = $analyzer->analyzeBatch($chunk);
                if (count($chunkResults) !== count($chunk)
                    || collect($chunkResults)->contains(
                        fn (AudioAnalyzerResult $result): bool => $result->status !== 'completed',
                    )) {
                    throw new RuntimeException('The analyzer did not complete every benchmark track.');
                }
                array_push($configurationResults, ...$chunkResults);
            }
        } catch (Throwable $exception) {
            return [[
                ...$configuration,
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 2000),
            ], null];
        } finally {
            $analyzer->shutdown();
        }

        $wallTimeMs = max(1, (int) round((hrtime(true) - $started) / 1_000_000));
        $equivalence = $references === null
            ? ['equivalent' => true, 'minimumCosine' => 1.0, 'featuresMatch' => true]
            : $this->equivalence($references, $configurationResults);

        return [[
            ...$configuration,
            'status' => 'completed',
            'trackCount' => count($configurationResults),
            'wallTimeMs' => $wallTimeMs,
            'tracksPerMinute' => round(count($configurationResults) / $wallTimeMs * 60_000, 3),
            'averageTimings' => $this->averageTimings($configurationResults),
            ...$equivalence,
        ], $configurationResults];
    }

    /** @param  list<AudioAnalyzerResult>  $results
     *  @return array<string, int>
     */
    private function averageTimings(array $results): array
    {
        $fields = ['decodeMs', 'featureExtractionMs', 'embeddingMs'];

        return collect($fields)->mapWithKeys(
            fn (string $field): array => [
                $field => (int) round(collect($results)->average(
                    fn (AudioAnalyzerResult $result): int => $result->timings[$field] ?? 0,
                )),
            ],
        )->all();
    }

    /**
     * @param  list<AudioAnalyzerResult>  $references
     * @param  list<AudioAnalyzerResult>  $candidates
     * @return array{equivalent: bool, minimumCosine: float, featuresMatch: bool}
     */
    private function equivalence(array $references, array $candidates): array
    {
        $referenceById = collect($references)->keyBy('itemId');
        $minimumCosine = 1.0;
        $featuresMatch = true;

        foreach ($candidates as $candidate) {
            $reference = $referenceById->get($candidate->itemId);
            if (! $reference instanceof AudioAnalyzerResult) {
                return [
                    'equivalent' => false,
                    'minimumCosine' => 0.0,
                    'featuresMatch' => false,
                ];
            }

            $minimumCosine = min(
                $minimumCosine,
                $this->cosine($reference->embedding, $candidate->embedding),
            );
            $featuresMatch = $featuresMatch && $reference->features === $candidate->features;
        }

        return [
            'equivalent' => $minimumCosine >= 0.999999 && $featuresMatch,
            'minimumCosine' => round($minimumCosine, 9),
            'featuresMatch' => $featuresMatch,
        ];
    }

    /** @param  list<float>  $left
     *  @param  list<float>  $right
     */
    private function cosine(array $left, array $right): float
    {
        if (count($left) !== count($right) || $left === []) {
            return 0.0;
        }

        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;
        foreach ($left as $index => $leftValue) {
            $rightValue = $right[$index];
            $dot += $leftValue * $rightValue;
            $leftNorm += $leftValue ** 2;
            $rightNorm += $rightValue ** 2;
        }

        if ($leftNorm <= 0 || $rightNorm <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }

    /** @param  list<array<string, mixed>>  $results */
    private function storeProgress(AudioAnalyzerBenchmark $benchmark, array $results): void
    {
        $benchmark->update([
            'results' => $results,
            'completed_configuration_count' => count($results),
        ]);
    }

    private function cancelIfRequested(AudioAnalyzerBenchmark $benchmark): bool
    {
        if ($benchmark->fresh()->cancel_requested_at === null) {
            return false;
        }

        $benchmark->update([
            'status' => 'cancelled',
            'finished_at' => now(),
        ]);

        return true;
    }

    /** @param  list<array<string, mixed>>  $results
     *  @return array<string, mixed>|null
     */
    private function fastestEquivalent(array $results): ?array
    {
        $winner = Collection::make($results)
            ->filter(
                fn (array $result): bool => ($result['status'] ?? null) === 'completed'
                    && ($result['equivalent'] ?? false) === true,
            )
            ->sortBy('wallTimeMs')
            ->first();
        if (! is_array($winner)) {
            return null;
        }

        $cpuBaseline = Collection::make($results)->first(
            fn (array $result): bool => ($result['status'] ?? null) === 'completed'
                && ($result['accelerator'] ?? null) === 'cpu'
                && ($result['chunkSize'] ?? null) === 5,
        );
        $speedup = is_array($cpuBaseline)
            ? (int) $cpuBaseline['wallTimeMs'] / max(1, (int) $winner['wallTimeMs'])
            : null;

        return [
            'accelerator' => $winner['accelerator'],
            'preparationWorkers' => $winner['preparationWorkers'],
            'chunkSize' => $winner['chunkSize'],
            'tracksPerMinute' => $winner['tracksPerMinute'],
            'speedupVsCpu' => $speedup === null ? null : round($speedup, 3),
        ];
    }
}
