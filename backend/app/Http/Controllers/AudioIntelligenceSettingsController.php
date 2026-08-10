<?php

namespace App\Http\Controllers;

use App\Jobs\PrepareAudioAnalysisRun;
use App\Jobs\RunAudioAnalysis;
use App\Jobs\RunAudioAnalyzerBenchmark;
use App\Models\ApplicationSetting;
use App\Models\AudioAnalysisRun;
use App\Models\AudioAnalyzerBenchmark;
use App\Models\LibraryRoot;
use App\Music\Intelligence\AudioAnalysisProfileRegistry;
use App\Music\Intelligence\AudioAnalyzer;
use App\Music\Intelligence\AudioAnalyzerHealth;
use App\Music\Intelligence\AudioAnalysisRunPlanner;
use App\Music\Intelligence\AudioVectorIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class AudioIntelligenceSettingsController extends Controller
{
    private const ANALYZER_HEALTH_CACHE_KEY = 'sonotheque:audio-intelligence:analyzer-health:v2';

    public function __construct(
        private readonly AudioAnalysisRunPlanner $runPlanner,
        private readonly AudioAnalyzer $analyzer,
        private readonly AudioAnalysisProfileRegistry $profileRegistry,
        private readonly AudioVectorIndex $vectorIndex,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload(ApplicationSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'validationSampleSize' => [
                'required',
                'integer',
                'min:'.AudioAnalysisRunPlanner::MINIMUM_VALIDATION_SAMPLE_SIZE,
                'max:'.AudioAnalysisRunPlanner::MAXIMUM_VALIDATION_SAMPLE_SIZE,
            ],
            'accelerator' => ['sometimes', 'string', Rule::in(['cpu', 'cuda'])],
            'reranking' => ['sometimes', 'array'],
            'reranking.enabled' => ['required_with:reranking', 'boolean'],
            'reranking.tempoInfluence' => [
                'required_with:reranking',
                'integer',
                'min:0',
                'max:10',
            ],
            'reranking.keyInfluence' => [
                'required_with:reranking',
                'integer',
                'min:0',
                'max:10',
            ],
            'reranking.intensityInfluence' => [
                'required_with:reranking',
                'integer',
                'min:0',
                'max:10',
            ],
        ]);
        $settings = ApplicationSetting::current();
        $accelerator = $validated['accelerator'] ?? $settings->audioIntelligenceAccelerator();
        if ($accelerator !== $settings->audioIntelligenceAccelerator()) {
            $this->abortIfAnalysisIsRunning();
            $this->abortIfBenchmarkActive();
            Cache::forget(self::ANALYZER_HEALTH_CACHE_KEY);
        }
        $settings->update([
            'audio_intelligence_enabled' => $validated['enabled'],
            'audio_intelligence_validation_sample_size' => $validated['validationSampleSize'],
            'audio_intelligence_accelerator' => $accelerator,
            'audio_similarity_reranking_enabled' => $validated['reranking']['enabled']
                ?? $settings->audio_similarity_reranking_enabled,
            'audio_similarity_tempo_influence' => $validated['reranking']['tempoInfluence']
                ?? $settings->audio_similarity_tempo_influence,
            'audio_similarity_key_influence' => $validated['reranking']['keyInfluence']
                ?? $settings->audio_similarity_key_influence,
            'audio_similarity_intensity_influence' => $validated['reranking']['intensityInfluence']
                ?? $settings->audio_similarity_intensity_influence,
        ]);

        return response()->json($this->payload($settings));
    }

    public function prepareValidationSample(): JsonResponse
    {
        $settings = ApplicationSetting::current();
        abort_unless(
            $settings->audio_intelligence_enabled,
            409,
            'Enable audio intelligence before preparing a validation sample.',
        );
        $this->abortIfRunActive();

        $run = $this->runPlanner->prepareValidationSample(
            $settings->audio_intelligence_validation_sample_size,
        );
        PrepareAudioAnalysisRun::dispatch($run->id);

        return response()->json($this->payload($settings, $run), 202);
    }

    public function prepareExpansion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'targetTrackCount' => [
                'required',
                'integer',
                'min:'.AudioAnalysisRunPlanner::MINIMUM_VALIDATION_SAMPLE_SIZE,
                'max:'.AudioAnalysisRunPlanner::MAXIMUM_REVIEW_POOL_SIZE,
            ],
        ]);
        $settings = ApplicationSetting::current();
        abort_unless(
            $settings->audio_intelligence_enabled,
            409,
            'Enable audio intelligence before expanding the analyzed pool.',
        );
        $this->abortIfRunActive();

        $health = $this->analyzer->health();
        $this->cacheAnalyzerHealth($health);
        abort_unless(
            $health->ready(),
            409,
            $health->message ?? 'The audio analyzer is not ready.',
        );
        $profile = $this->profileRegistry->resolve($health->profile);
        $analyzedTrackCount = $this->runPlanner->analyzedTrackCount($profile);
        abort_if(
            $analyzedTrackCount === 0,
            409,
            'Complete an initial validation run before expanding the analyzed pool.',
        );
        abort_unless(
            $validated['targetTrackCount'] > $analyzedTrackCount,
            409,
            'The expansion target must exceed the current analyzed track count.',
        );

        $run = $this->runPlanner->prepareExpansion(
            $validated['targetTrackCount'],
            $profile,
        );
        PrepareAudioAnalysisRun::dispatch($run->id);

        return response()->json($this->payload($settings, $run, $health), 202);
    }

    public function prepareCollection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'libraryRootId' => ['nullable', 'integer', 'exists:library_roots,id'],
        ]);
        $settings = ApplicationSetting::current();
        abort_unless(
            $settings->audio_intelligence_enabled,
            409,
            'Enable audio intelligence before analyzing the collection.',
        );
        $this->abortIfRunActive();

        $health = $this->analyzer->health();
        $this->cacheAnalyzerHealth($health);
        abort_unless(
            $health->ready(),
            409,
            $health->message ?? 'The audio analyzer is not ready.',
        );
        $profile = $this->profileRegistry->resolve($health->profile);
        abort_if(
            $this->runPlanner->analyzedTrackCount($profile) === 0,
            409,
            'Complete an initial validation run before analyzing the collection.',
        );

        $libraryRoot = isset($validated['libraryRootId'])
            ? LibraryRoot::query()->findOrFail($validated['libraryRootId'])
            : null;
        abort_if(
            $libraryRoot !== null && ! $libraryRoot->enabled,
            409,
            'The selected library root is disabled.',
        );

        $run = $this->runPlanner->prepareCollection($profile, $libraryRoot);
        PrepareAudioAnalysisRun::dispatch($run->id);

        return response()->json($this->payload($settings, $run, $health), 202);
    }

    public function testAnalyzer(): JsonResponse
    {
        $health = $this->analyzer->health();
        $this->cacheAnalyzerHealth($health);

        return response()->json($this->payload(ApplicationSetting::current(), health: $health));
    }

    public function startBenchmark(): JsonResponse
    {
        $settings = ApplicationSetting::current();
        abort_unless(
            $settings->audio_intelligence_enabled,
            409,
            'Enable audio intelligence before benchmarking the analyzer.',
        );
        $this->abortIfAnalysisIsRunning();
        abort_if(
            AudioAnalyzerBenchmark::query()
                ->whereIn('status', ['queued', 'running'])
                ->exists(),
            409,
            'An analyzer benchmark is already running.',
        );

        $benchmark = AudioAnalyzerBenchmark::create([
            'status' => 'queued',
            'sample_size' => max(
                5,
                min(
                    25,
                    (int) config('sonotheque.audio_intelligence.benchmark_sample_size', 15),
                ),
            ),
            'results' => [],
            'completed_configuration_count' => 0,
            'total_configuration_count' => 6,
        ]);
        RunAudioAnalyzerBenchmark::dispatch($benchmark->id);

        return response()->json(
            $this->payload($settings, benchmark: $benchmark),
            202,
        );
    }

    public function cancelBenchmark(AudioAnalyzerBenchmark $audioAnalyzerBenchmark): JsonResponse
    {
        abort_unless(
            in_array($audioAnalyzerBenchmark->status, ['queued', 'running'], true),
            409,
            'Only an active analyzer benchmark can be cancelled.',
        );

        $audioAnalyzerBenchmark->update(['cancel_requested_at' => now()]);
        if ($audioAnalyzerBenchmark->status === 'queued') {
            $audioAnalyzerBenchmark->update([
                'status' => 'cancelled',
                'finished_at' => now(),
            ]);
        }

        return response()->json($this->payload(
            ApplicationSetting::current(),
            benchmark: $audioAnalyzerBenchmark->fresh(),
        ));
    }

    public function startRun(AudioAnalysisRun $audioAnalysisRun): JsonResponse
    {
        $settings = ApplicationSetting::current();
        abort_unless(
            $settings->audio_intelligence_enabled,
            409,
            'Enable audio intelligence before starting an analysis run.',
        );
        abort_unless(
            $audioAnalysisRun->phase === 'preparation'
                && $audioAnalysisRun->status === 'prepared'
                && $audioAnalysisRun->selected_track_count > 0,
            409,
            'Only a prepared, non-empty analysis run can be started.',
        );
        $this->abortIfBenchmarkActive();

        $health = $this->analyzer->health();
        $this->cacheAnalyzerHealth($health);
        abort_unless(
            $health->ready(),
            409,
            $health->message ?? 'The audio analyzer is not ready.',
        );

        $profile = $this->profileRegistry->resolve($health->profile);
        abort_if(
            $audioAnalysisRun->audio_analysis_profile_id !== null
                && $audioAnalysisRun->audio_analysis_profile_id !== $profile->id,
            409,
            'The prepared run belongs to a different analyzer profile.',
        );
        $audioAnalysisRun->update([
            'audio_analysis_profile_id' => $profile->id,
            'phase' => 'analysis',
            'status' => 'queued',
            'finished_at' => null,
            'cancel_requested_at' => null,
            'pause_requested_at' => null,
            'heartbeat_at' => null,
        ]);
        $audioAnalysisRun->items()->where('status', 'selected')->update(['status' => 'queued']);
        RunAudioAnalysis::dispatch($audioAnalysisRun->id);

        return response()->json(
            $this->payload($settings, $audioAnalysisRun->fresh(), $health),
            202,
        );
    }

    public function cancelRun(AudioAnalysisRun $audioAnalysisRun): JsonResponse
    {
        abort_unless(
            in_array(
                $audioAnalysisRun->status,
                ['fingerprinting', 'prepared', 'queued', 'running', 'paused'],
                true,
            ),
            409,
            'Only an active analysis run can be cancelled.',
        );

        $audioAnalysisRun->update(['cancel_requested_at' => now()]);
        if (in_array($audioAnalysisRun->status, ['prepared', 'paused'], true)) {
            $audioAnalysisRun->items()
                ->whereIn('status', [
                    'pending_fingerprint',
                    'fingerprinting',
                    'fingerprint_failed',
                    'selected',
                    'queued',
                    'running',
                ])
                ->update(['status' => 'cancelled', 'error' => null]);
            $audioAnalysisRun->update([
                'status' => 'cancelled',
                'finished_at' => now(),
                'heartbeat_at' => now(),
            ]);
        }

        return response()->json($this->payload(
            ApplicationSetting::current(),
            $audioAnalysisRun->fresh(),
        ));
    }

    public function pauseRun(AudioAnalysisRun $audioAnalysisRun): JsonResponse
    {
        abort_unless(
            in_array($audioAnalysisRun->status, ['fingerprinting', 'queued', 'running'], true),
            409,
            'Only an active audio analysis run can be paused.',
        );

        $audioAnalysisRun->update(['pause_requested_at' => now()]);

        return response()->json($this->payload(
            ApplicationSetting::current(),
            $audioAnalysisRun->fresh(),
        ));
    }

    public function resumeRun(AudioAnalysisRun $audioAnalysisRun): JsonResponse
    {
        $settings = ApplicationSetting::current();
        abort_unless(
            $settings->audio_intelligence_enabled,
            409,
            'Enable audio intelligence before resuming an analysis run.',
        );
        abort_unless(
            $this->canResume($audioAnalysisRun),
            409,
            'This analysis run is either complete or still has an active worker.',
        );
        $this->abortIfBenchmarkActive();

        $summary = $audioAnalysisRun->summary ?? [];
        if ($audioAnalysisRun->phase === 'preparation') {
            unset($summary['fingerprintPreparationError']);
            $audioAnalysisRun->items()
                ->whereIn('status', ['fingerprint_failed', 'cancelled', 'fingerprinting'])
                ->update([
                    'status' => 'pending_fingerprint',
                    'error' => null,
                ]);
            $status = 'fingerprinting';
        } else {
            unset($summary['analysisError']);
            $audioAnalysisRun->items()
                ->whereIn('status', ['failed', 'cancelled', 'running'])
                ->update([
                    'status' => 'queued',
                    'error' => null,
                ]);
            $status = 'queued';
        }
        $audioAnalysisRun->update([
            'status' => $status,
            'summary' => $summary,
            'finished_at' => null,
            'cancel_requested_at' => null,
            'pause_requested_at' => null,
            'heartbeat_at' => null,
        ]);
        if ($audioAnalysisRun->phase === 'preparation') {
            PrepareAudioAnalysisRun::dispatch($audioAnalysisRun->id);
        } else {
            RunAudioAnalysis::dispatch($audioAnalysisRun->id);
        }

        return response()->json($this->payload($settings, $audioAnalysisRun->fresh()), 202);
    }

    /**
     * @return array{
     *     enabled: bool,
     *     validationSampleSize: int,
     *     eligibleTrackCount: int,
     *     fingerprintedTrackCount: int,
     *     analyzerStatus: string,
     *     analyzer: array<string, mixed>,
     *     analyzerSelection: array<string, mixed>,
     *     vectorIndex: array<string, mixed>,
     *     eligibleRoots: array<int, array<string, mixed>>,
     *     collectionRuns: array<int, array<string, mixed>>,
     *     latestCollectionRun: ?array<string, mixed>,
     *     latestValidationRun: ?array<string, mixed>,
     *     activeRun: ?array<string, mixed>,
     *     latestBenchmark: ?array<string, mixed>
     * }
     */
    private function payload(
        ApplicationSetting $settings,
        ?AudioAnalysisRun $run = null,
        ?AudioAnalyzerHealth $health = null,
        ?AudioAnalyzerBenchmark $benchmark = null,
    ): array {
        $analyzer = $health?->toArray() ?? $this->cachedAnalyzerHealth();
        $latestCollectionRun = $run?->kind === 'collection'
            ? $run
            : AudioAnalysisRun::query()
                ->where('kind', 'collection')
                ->latest('id')
                ->first();
        $latestValidationRun = $run !== null && $run->kind !== 'collection'
            ? $run
            : AudioAnalysisRun::query()
                ->where('kind', '!=', 'collection')
                ->latest('id')
                ->first();
        $activeRun = $run !== null && in_array(
            $run->status,
            ['fingerprinting', 'prepared', 'queued', 'running', 'paused'],
            true,
        )
            ? $run
            : AudioAnalysisRun::query()
                ->whereIn('status', [
                    'fingerprinting',
                    'prepared',
                    'queued',
                    'running',
                    'paused',
                ])
                ->latest('id')
                ->first();
        $coverage = $this->runPlanner->coverage();

        $latestBenchmark = $benchmark ?? AudioAnalyzerBenchmark::query()->latest('id')->first();

        return [
            'enabled' => $settings->audio_intelligence_enabled,
            'validationSampleSize' => $settings->audio_intelligence_validation_sample_size,
            'reranking' => $settings->audioSimilarityReranking(),
            'eligibleTrackCount' => $coverage['eligibleTrackCount'],
            'fingerprintedTrackCount' => $coverage['fingerprintedTrackCount'],
            'eligibleRoots' => $this->runPlanner->eligibleRoots()
                ->map(fn (object $root): array => [
                    'id' => $root->id,
                    'name' => $root->name,
                    'eligibleTrackCount' => $root->eligible_track_count,
                    'fingerprintedTrackCount' => $root->fingerprinted_track_count,
                ])
                ->values()
                ->all(),
            'analyzerStatus' => $analyzer['status'],
            'analyzer' => $analyzer,
            'analyzerSelection' => $this->analyzerSelectionPayload(
                $settings,
                $latestBenchmark,
                $analyzer,
            ),
            'vectorIndex' => $this->vectorIndex->status(),
            'collectionRuns' => $this->latestCollectionRunPayloads(),
            'latestCollectionRun' => $this->runPayload($latestCollectionRun),
            'latestValidationRun' => $this->runPayload($latestValidationRun),
            'activeRun' => $this->runPayload($activeRun),
            'latestBenchmark' => $this->benchmarkPayload(
                $latestBenchmark,
            ),
        ];
    }

    /** @param array<string, mixed> $health */
    private function analyzerSelectionPayload(
        ApplicationSetting $settings,
        ?AudioAnalyzerBenchmark $benchmark,
        array $health,
    ): array {
        $selected = $settings->audioIntelligenceAccelerator();
        $statuses = collect(['cpu', 'cuda'])->mapWithKeys(
            fn (string $accelerator): array => [
                $accelerator => $this->acceleratorStatus($accelerator, $benchmark),
            ],
        )->all();
        if (($health['status'] ?? null) === 'ready') {
            $statuses[$selected] = 'available';
        }

        return [
            'selected' => $selected,
            'recommended' => is_array($benchmark?->recommendation)
                && in_array($benchmark->recommendation['accelerator'] ?? null, ['cpu', 'cuda'], true)
                    ? $benchmark->recommendation['accelerator']
                    : null,
            'methods' => $statuses,
        ];
    }

    private function acceleratorStatus(
        string $accelerator,
        ?AudioAnalyzerBenchmark $benchmark,
    ): string {
        $results = collect($benchmark?->results ?? [])
            ->where('accelerator', $accelerator);
        if ($results->contains('status', 'completed')) {
            return 'available';
        }
        if ($results->isNotEmpty() && $results->every(
            fn (array $result): bool => ($result['status'] ?? null) === 'unavailable',
        )) {
            return 'unavailable';
        }

        return 'unchecked';
    }

    /** @return array<int, array<string, mixed>> */
    private function latestCollectionRunPayloads(): array
    {
        $latestRunIds = AudioAnalysisRun::query()
            ->where('kind', 'collection')
            ->selectRaw('MAX(id)')
            ->groupBy('library_root_id');

        return AudioAnalysisRun::query()
            ->with(['profile', 'libraryRoot'])
            ->whereIn('id', $latestRunIds)
            ->latest('id')
            ->get()
            ->map(fn (AudioAnalysisRun $run): array => $this->runPayload($run) ?? [])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function cachedAnalyzerHealth(): array
    {
        $cached = Cache::get(self::ANALYZER_HEALTH_CACHE_KEY);
        if (is_array($cached)
            && in_array($cached['status'] ?? null, AudioAnalyzerHealth::STATUSES, true)) {
            return $cached;
        }

        if (config('sonotheque.audio_intelligence.driver') === 'none') {
            return new AudioAnalyzerHealth(
                status: 'not_configured',
                message: 'Configure an audio analyzer before checking its status.',
            )->toArray();
        }

        return new AudioAnalyzerHealth(
            status: 'unchecked',
            message: 'Run the analyzer check to verify the configured model.',
        )->toArray();
    }

    private function cacheAnalyzerHealth(AudioAnalyzerHealth $health): void
    {
        Cache::forever(self::ANALYZER_HEALTH_CACHE_KEY, $health->toArray());
    }

    /** @return array<string, mixed>|null */
    private function runPayload(?AudioAnalysisRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        $run->loadMissing(['profile', 'libraryRoot']);

        return [
            'id' => $run->id,
            'kind' => $run->kind,
            'phase' => $run->phase,
            'status' => $run->status,
            'requestedTrackCount' => $run->requested_track_count,
            'selectedTrackCount' => $run->selected_track_count,
            'summary' => $run->summary,
            'resumable' => $this->canResume($run),
            'libraryRoot' => $run->libraryRoot === null ? null : [
                'id' => $run->libraryRoot->id,
                'name' => $run->libraryRoot->name,
            ],
            'profile' => $run->profile === null ? null : [
                'analyzerName' => $run->profile->analyzer_name,
                'analyzerVersion' => $run->profile->analyzer_version,
                'analyzerLicense' => $run->profile->analyzer_license,
                'modelName' => $run->profile->model_name,
                'modelVersion' => $run->profile->model_version,
                'modelChecksum' => $run->profile->model_checksum,
                'modelLicense' => $run->profile->model_license,
                'embeddingDimensions' => $run->profile->embedding_dimensions,
            ],
            'startedAt' => $run->started_at?->toAtomString(),
            'finishedAt' => $run->finished_at?->toAtomString(),
            'cancelRequestedAt' => $run->cancel_requested_at?->toAtomString(),
            'pauseRequestedAt' => $run->pause_requested_at?->toAtomString(),
            'createdAt' => $run->created_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function benchmarkPayload(?AudioAnalyzerBenchmark $benchmark): ?array
    {
        if ($benchmark === null) {
            return null;
        }

        return [
            'id' => $benchmark->id,
            'status' => $benchmark->status,
            'sampleSize' => $benchmark->sample_size,
            'sampleTrackIds' => $benchmark->sample_track_ids ?? [],
            'results' => $benchmark->results ?? [],
            'recommendation' => $benchmark->recommendation,
            'completedConfigurationCount' => $benchmark->completed_configuration_count,
            'totalConfigurationCount' => $benchmark->total_configuration_count,
            'error' => $benchmark->error,
            'cancelRequestedAt' => $benchmark->cancel_requested_at?->toAtomString(),
            'startedAt' => $benchmark->started_at?->toAtomString(),
            'finishedAt' => $benchmark->finished_at?->toAtomString(),
            'createdAt' => $benchmark->created_at?->toAtomString(),
        ];
    }

    private function canResume(AudioAnalysisRun $run): bool
    {
        if ($run->status === 'paused') {
            return true;
        }
        if (in_array($run->status, ['failed', 'partial', 'cancelled'], true)) {
            return true;
        }
        if (! in_array($run->status, ['fingerprinting', 'queued', 'running'], true)) {
            return false;
        }

        $lastActivity = $run->heartbeat_at ?? $run->updated_at;
        $staleMinutes = max(
            1,
            (int) config('sonotheque.audio_intelligence.resume_stale_minutes', 10),
        );

        return $lastActivity !== null && $lastActivity->lte(now()->subMinutes($staleMinutes));
    }

    private function abortIfRunActive(): void
    {
        abort_if(
            AudioAnalysisRun::query()
                ->whereIn('status', [
                    'fingerprinting',
                    'prepared',
                    'queued',
                    'running',
                    'paused',
                ])
                ->exists(),
            409,
            'Wait for the active audio analysis run to finish or cancel it first.',
        );
    }

    private function abortIfAnalysisIsRunning(): void
    {
        abort_if(
            AudioAnalysisRun::query()
                ->whereIn('status', ['fingerprinting', 'queued', 'running'])
                ->exists(),
            409,
            'Pause or finish the active audio analysis before starting a benchmark.',
        );
    }

    private function abortIfBenchmarkActive(): void
    {
        abort_if(
            AudioAnalyzerBenchmark::query()
                ->whereIn('status', ['queued', 'running'])
                ->exists(),
            409,
            'Wait for the analyzer benchmark to finish or cancel it first.',
        );
    }
}
