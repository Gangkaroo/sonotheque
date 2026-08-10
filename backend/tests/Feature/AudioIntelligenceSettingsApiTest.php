<?php

namespace Tests\Feature;

use App\Jobs\PrepareAudioAnalysisRun;
use App\Jobs\RunAudioAnalysis;
use App\Jobs\RunAudioAnalyzerBenchmark;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\AudioAnalysisArtifact;
use App\Models\AudioAnalysisRun;
use App\Models\AudioAnalyzerBenchmark;
use App\Models\Genre;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Track;
use App\Music\Intelligence\AudioAnalyzer;
use App\Music\Intelligence\AudioAnalysisProfileRegistry;
use App\Music\Intelligence\AudioVectorIndex;
use App\Music\Intelligence\EssentiaDockerAudioAnalyzer;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Fakes\FakeAudioAnalyzer;
use Tests\Fakes\FakeAudioContentFingerprinter;

class AudioIntelligenceSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_audio_intelligence_is_disabled_and_unprovisioned_by_default(): void
    {
        $this->getJson('/api/settings/audio-intelligence')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('validationSampleSize', 200)
            ->assertJsonPath('eligibleTrackCount', 0)
            ->assertJsonPath('fingerprintedTrackCount', 0)
            ->assertJsonPath('analyzerStatus', 'not_configured')
            ->assertJsonPath('analyzer.status', 'not_configured')
            ->assertJsonPath('analyzerSelection.selected', 'cpu')
            ->assertJsonPath('analyzerSelection.recommended', null)
            ->assertJsonPath('analyzerSelection.methods.cpu', 'unchecked')
            ->assertJsonPath('analyzerSelection.methods.cuda', 'unchecked')
            ->assertJsonPath('reranking.enabled', false)
            ->assertJsonPath('reranking.tempoInfluence', 5)
            ->assertJsonPath('reranking.keyInfluence', 3)
            ->assertJsonPath('reranking.intensityInfluence', 4)
            ->assertJsonPath('vectorIndex.status', 'empty')
            ->assertJsonPath('vectorIndex.indexedArtifactCount', 0)
            ->assertJsonCount(0, 'collectionRuns')
            ->assertJsonPath('latestCollectionRun', null)
            ->assertJsonPath('latestValidationRun', null)
            ->assertJsonPath('activeRun', null)
            ->assertJsonPath('latestBenchmark', null);

        $settings = ApplicationSetting::current();
        $this->assertFalse($settings->audio_intelligence_enabled);
        $this->assertSame(200, $settings->audio_intelligence_validation_sample_size);
        $this->assertSame('cpu', $settings->audioIntelligenceAccelerator());
        $this->assertDatabaseCount('audio_analysis_runs', 0);
        $this->assertDatabaseCount('audio_analysis_run_items', 0);
    }

    public function test_loading_settings_does_not_start_the_analyzer(): void
    {
        config()->set('sonotheque.audio_intelligence.driver', 'essentia_docker');
        Cache::forget('sonotheque:audio-intelligence:analyzer-health:v2');
        $analyzer = FakeAudioAnalyzer::ready();
        $this->app->instance(AudioAnalyzer::class, $analyzer);

        $this->getJson('/api/settings/audio-intelligence')
            ->assertOk()
            ->assertJsonPath('analyzerStatus', 'unchecked');
        $this->assertSame(0, $analyzer->healthCalls);

        $this->postJson('/api/settings/audio-intelligence/analyzer/test')
            ->assertOk()
            ->assertJsonPath('analyzerStatus', 'ready');
        $this->assertSame(1, $analyzer->healthCalls);

        $this->getJson('/api/settings/audio-intelligence')
            ->assertOk()
            ->assertJsonPath('analyzerStatus', 'ready');
        $this->assertSame(1, $analyzer->healthCalls);
    }

    public function test_settings_require_explicit_valid_opt_in_values(): void
    {
        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => true,
            'validationSampleSize' => 49,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('validationSampleSize');

        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => 'yes',
            'validationSampleSize' => 200,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');

        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => true,
            'validationSampleSize' => 500,
        ])->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('validationSampleSize', 500)
            ->assertJsonPath('analyzerStatus', 'not_configured');

        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => true,
            'validationSampleSize' => 500,
            'reranking' => [
                'enabled' => true,
                'tempoInfluence' => 7,
                'keyInfluence' => 4,
                'intensityInfluence' => 11,
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('reranking.intensityInfluence');

        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => true,
            'validationSampleSize' => 500,
            'reranking' => [
                'enabled' => true,
                'tempoInfluence' => 7,
                'keyInfluence' => 4,
                'intensityInfluence' => 6,
            ],
        ])->assertOk()
            ->assertJsonPath('reranking.enabled', true)
            ->assertJsonPath('reranking.tempoInfluence', 7)
            ->assertJsonPath('reranking.keyInfluence', 4)
            ->assertJsonPath('reranking.intensityInfluence', 6);
    }

    public function test_analyzer_method_is_persisted_and_uses_benchmark_guidance(): void
    {
        config()->set('sonotheque.audio_intelligence.driver', 'essentia_docker');
        config()->set('sonotheque.audio_intelligence.accelerator', 'cpu');
        config()->set('sonotheque.audio_intelligence.docker_image', 'custom-cpu-image');
        config()->set('sonotheque.audio_intelligence.benchmark_cuda_image', 'cuda-image');
        AudioAnalyzerBenchmark::create([
            'status' => 'completed',
            'sample_size' => 15,
            'results' => [
                ['accelerator' => 'cpu', 'status' => 'completed'],
                ['accelerator' => 'cuda', 'status' => 'completed'],
            ],
            'recommendation' => [
                'accelerator' => 'cuda',
                'preparationWorkers' => 2,
                'chunkSize' => 15,
                'tracksPerMinute' => 12.5,
                'speedupVsCpu' => 3.2,
            ],
            'completed_configuration_count' => 2,
            'total_configuration_count' => 2,
            'finished_at' => now(),
        ]);

        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => true,
            'validationSampleSize' => 200,
            'accelerator' => 'cuda',
        ])->assertOk()
            ->assertJsonPath('analyzerSelection.selected', 'cuda')
            ->assertJsonPath('analyzerSelection.recommended', 'cuda')
            ->assertJsonPath('analyzerSelection.methods.cpu', 'available')
            ->assertJsonPath('analyzerSelection.methods.cuda', 'available');

        $this->assertSame(
            'cuda',
            ApplicationSetting::current()->audioIntelligenceAccelerator(),
        );
        $analyzer = app(AudioAnalyzer::class);
        $this->assertInstanceOf(EssentiaDockerAudioAnalyzer::class, $analyzer);
        $accelerator = new \ReflectionProperty($analyzer, 'accelerator');
        $this->assertSame('cuda', $accelerator->getValue($analyzer));
        $image = new \ReflectionProperty($analyzer, 'image');
        $this->assertSame('cuda-image', $image->getValue($analyzer));

        AudioAnalysisRun::create([
            'kind' => 'collection',
            'phase' => 'analysis',
            'status' => 'running',
            'selection_seed' => fake()->uuid(),
            'requested_track_count' => 1,
            'selected_track_count' => 1,
        ]);
        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => true,
            'validationSampleSize' => 200,
            'accelerator' => 'cpu',
        ])->assertStatus(409);
        $this->assertSame(
            'cuda',
            ApplicationSetting::current()->audioIntelligenceAccelerator(),
        );
    }

    public function test_it_starts_and_cancels_a_bounded_analyzer_benchmark(): void
    {
        Queue::fake();
        config()->set('sonotheque.audio_intelligence.benchmark_sample_size', 15);

        $this->postJson('/api/settings/audio-intelligence/benchmarks')
            ->assertStatus(409);

        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => true,
            'validationSampleSize' => 200,
        ])->assertOk();

        $response = $this->postJson('/api/settings/audio-intelligence/benchmarks')
            ->assertAccepted()
            ->assertJsonPath('latestBenchmark.status', 'queued')
            ->assertJsonPath('latestBenchmark.sampleSize', 15)
            ->assertJsonPath('latestBenchmark.totalConfigurationCount', 6);
        $benchmark = AudioAnalyzerBenchmark::findOrFail(
            $response->json('latestBenchmark.id'),
        );

        Queue::assertPushed(
            RunAudioAnalyzerBenchmark::class,
            fn (RunAudioAnalyzerBenchmark $job): bool => $job->audioAnalyzerBenchmarkId
                === $benchmark->id
                && $job->queue === 'analysis',
        );

        $this->postJson(
            "/api/settings/audio-intelligence/benchmarks/{$benchmark->id}/cancel",
        )
            ->assertOk()
            ->assertJsonPath('latestBenchmark.status', 'cancelled');
        $this->assertNotNull($benchmark->fresh()->cancel_requested_at);
    }

    public function test_validation_preparation_requires_opt_in_and_dispatches_no_job(): void
    {
        Queue::fake();

        $this->postJson('/api/settings/audio-intelligence/validation-runs')
            ->assertStatus(409);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('audio_analysis_runs', 0);
    }

    public function test_it_queues_a_diverse_catalog_sample_for_bounded_fingerprint_preparation(): void
    {
        Queue::fake();
        $library = Library::create(['name' => 'Validation library']);
        $genres = collect(['Ambient', 'Jazz', 'Metal'])
            ->map(fn (string $name): Genre => Genre::create(['name' => $name]));
        $firstRoot = $this->createCatalog($library, 'First', 30, $genres->all());
        $secondRoot = $this->createCatalog($library, 'Second', 30, $genres->all());
        $thirdRoot = $this->createCatalog(
            $library,
            'No fingerprints',
            4,
            $genres->all(),
            fingerprinted: false,
        );

        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => true,
            'validationSampleSize' => 50,
        ])->assertOk();

        $response = $this->postJson('/api/settings/audio-intelligence/validation-runs')
            ->assertAccepted()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('validationSampleSize', 50)
            ->assertJsonPath('eligibleTrackCount', 64)
            ->assertJsonPath('fingerprintedTrackCount', 60)
            ->assertJsonPath('analyzerStatus', 'not_configured')
            ->assertJsonPath('latestCollectionRun', null)
            ->assertJsonPath('latestValidationRun.phase', 'preparation')
            ->assertJsonPath('latestValidationRun.status', 'fingerprinting')
            ->assertJsonPath('latestValidationRun.requestedTrackCount', 50)
            ->assertJsonPath('latestValidationRun.selectedTrackCount', 0)
            ->assertJsonPath('latestValidationRun.summary.eligibleRootCount', 3)
            ->assertJsonPath('latestValidationRun.summary.candidateTrackCount', 60)
            ->assertJsonPath('latestValidationRun.summary.candidateRootCount', 3)
            ->assertJsonPath('latestValidationRun.summary.candidateGenreCount', 3)
            ->assertJsonPath('activeRun.id', fn (mixed $value): bool => is_int($value));

        $runId = $response->json('latestValidationRun.id');
        $this->assertDatabaseCount('audio_analysis_runs', 1);
        $this->assertDatabaseCount('audio_analysis_run_items', 60);
        $this->assertSame(
            [$firstRoot->id, $secondRoot->id, $thirdRoot->id],
            DB::table('audio_analysis_run_items')
                ->where('audio_analysis_run_id', $runId)
                ->distinct()
                ->orderBy('library_root_id')
                ->pluck('library_root_id')
                ->all(),
        );
        $this->assertSame(
            0,
            DB::table('audio_analysis_run_items')
                ->where('audio_analysis_run_id', $runId)
                ->whereNotNull('content_fingerprint')
                ->where('content_fingerprint_version', 1)
                ->count(),
        );
        $this->assertSame('fingerprinting', AudioAnalysisRun::findOrFail($runId)->status);
        Queue::assertPushed(
            PrepareAudioAnalysisRun::class,
            fn (PrepareAudioAnalysisRun $job): bool => $job->audioAnalysisRunId === $runId
                && $job->queue === 'analysis',
        );
        Queue::assertNotPushed(RunAudioAnalysis::class);
    }

    public function test_prepared_validation_run_starts_with_a_ready_analyzer(): void
    {
        Queue::fake();
        $library = Library::create(['name' => 'Validation library']);
        $genre = Genre::create(['name' => 'Ambient']);
        $this->createCatalog($library, 'First', 50, [$genre]);
        ApplicationSetting::current()->update([
            'audio_intelligence_enabled' => true,
            'audio_intelligence_validation_sample_size' => 50,
        ]);
        $run = app(\App\Music\Intelligence\AudioAnalysisRunPlanner::class)
            ->prepareValidationSample(50);
        $run->items()->each(function ($item): void {
            $fingerprint = $item->track->mediaFile->content_fingerprint;
            $item->update([
                'content_fingerprint' => $fingerprint,
                'content_fingerprint_version' => 1,
                'status' => 'selected',
            ]);
        });
        $run->update([
            'status' => 'prepared',
            'selected_track_count' => 50,
        ]);

        $this->app->instance(AudioAnalyzer::class, FakeAudioAnalyzer::ready());
        $this->postJson("/api/settings/audio-intelligence/runs/{$run->id}/start")
            ->assertAccepted()
            ->assertJsonPath('analyzer.status', 'ready')
            ->assertJsonPath('latestValidationRun.status', 'queued')
            ->assertJsonPath('latestValidationRun.profile.modelName', 'Test model')
            ->assertJsonPath('activeRun.id', $run->id);

        $this->assertDatabaseHas('audio_analysis_profiles', [
            'profile_key' => 'test-analyzer',
            'embedding_dimensions' => 3,
        ]);
        $this->assertDatabaseHas('audio_analysis_runs', [
            'id' => $run->id,
            'phase' => 'analysis',
            'status' => 'queued',
        ]);
        $this->assertSame(
            50,
            DB::table('audio_analysis_run_items')
                ->where('audio_analysis_run_id', $run->id)
                ->where('status', 'queued')
                ->count(),
        );
        Queue::assertPushed(
            RunAudioAnalysis::class,
            fn (RunAudioAnalysis $job): bool => $job->audioAnalysisRunId === $run->id
                && $job->queue === 'analysis',
        );
        $this->postJson("/api/settings/audio-intelligence/runs/{$run->id}/resume")
            ->assertStatus(409);

        $this->postJson("/api/settings/audio-intelligence/runs/{$run->id}/cancel")
            ->assertOk()
            ->assertJsonPath('latestValidationRun.status', 'queued')
            ->assertJsonPath('latestValidationRun.cancelRequestedAt', fn (mixed $value): bool => is_string($value));
        $this->assertNotNull($run->fresh()->cancel_requested_at);

        $run->update(['status' => 'cancelled', 'finished_at' => now()]);
        $run->items()->update(['status' => 'cancelled']);
        $this->postJson("/api/settings/audio-intelligence/runs/{$run->id}/resume")
            ->assertAccepted()
            ->assertJsonPath('latestValidationRun.status', 'queued')
            ->assertJsonPath('latestValidationRun.cancelRequestedAt', null)
            ->assertJsonPath('latestValidationRun.resumable', false);
        $this->assertSame(
            50,
            $run->items()->where('status', 'queued')->count(),
        );
        Queue::assertPushed(RunAudioAnalysis::class, 2);
    }

    public function test_it_prepares_an_incremental_expansion_that_keeps_existing_artifacts(): void
    {
        Queue::fake();
        $library = Library::create(['name' => 'Expansion library']);
        $genre = Genre::create(['name' => 'Ambient']);
        $this->createCatalog($library, 'Expansion', 60, [$genre]);
        $analyzer = FakeAudioAnalyzer::ready();
        $this->app->instance(AudioAnalyzer::class, $analyzer);
        $profile = app(AudioAnalysisProfileRegistry::class)->resolve($analyzer->health->profile);
        $baselineRun = AudioAnalysisRun::create([
            'audio_analysis_profile_id' => $profile->id,
            'phase' => 'analysis',
            'status' => 'completed',
            'selection_seed' => fake()->uuid(),
            'requested_track_count' => 10,
            'selected_track_count' => 10,
            'summary' => ['analyzedTrackCount' => 10],
        ]);
        $baselineTracks = Track::query()->with('mediaFile')->orderBy('id')->limit(10)->get();
        foreach ($baselineTracks as $track) {
            $artifact = AudioAnalysisArtifact::create([
                'audio_analysis_profile_id' => $profile->id,
                'content_fingerprint' => $track->mediaFile->content_fingerprint,
                'content_fingerprint_version' => 1,
                'features' => ['bpm' => 120],
                'embedding' => [1, 0, 0],
                'runtime_ms' => 100,
                'windows_analyzed' => 1,
            ]);
            $baselineRun->items()->create([
                'track_id' => $track->id,
                'library_root_id' => $track->mediaFile->library_root_id,
                'genre_id' => $genre->id,
                'audio_analysis_artifact_id' => $artifact->id,
                'content_fingerprint' => $track->mediaFile->content_fingerprint,
                'content_fingerprint_version' => 1,
                'position' => $track->id,
                'status' => 'completed',
            ]);
        }
        ApplicationSetting::current()->update([
            'audio_intelligence_enabled' => true,
            'audio_intelligence_validation_sample_size' => 50,
        ]);

        $response = $this->postJson('/api/settings/audio-intelligence/expansions', [
            'targetTrackCount' => 50,
        ])
            ->assertAccepted()
            ->assertJsonPath('latestValidationRun.summary.mode', 'expansion')
            ->assertJsonPath('latestValidationRun.summary.baselineAnalyzedTrackCount', 10)
            ->assertJsonPath('latestValidationRun.summary.newTrackTargetCount', 40)
            ->assertJsonPath('latestValidationRun.requestedTrackCount', 50);

        $runId = $response->json('latestValidationRun.id');
        $run = AudioAnalysisRun::findOrFail($runId);
        $this->assertSame($profile->id, $run->audio_analysis_profile_id);
        $this->assertSame(
            $baselineTracks->pluck('id')->sort()->values()->all(),
            $run->items()
                ->whereIn('track_id', $baselineTracks->pluck('id'))
                ->pluck('track_id')
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertSame(60, $run->items()->distinct()->count('track_id'));
        Queue::assertPushed(
            PrepareAudioAnalysisRun::class,
            fn (PrepareAudioAnalysisRun $job): bool => $job->audioAnalysisRunId === $runId
                && $job->queue === 'analysis',
        );

        $this->postJson('/api/settings/audio-intelligence/expansions', [
            'targetTrackCount' => 50,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Wait for the active audio analysis run to finish or cancel it first.');
        $this->assertSame(2, AudioAnalysisRun::count());
    }

    public function test_collection_analysis_is_root_scoped_idempotent_and_pausable(): void
    {
        Queue::fake();
        $library = Library::create(['name' => 'Collection library']);
        $genre = Genre::create(['name' => 'Ambient']);
        $firstRoot = $this->createCatalog($library, 'First collection root', 2, [$genre]);
        $secondRoot = $this->createCatalog($library, 'Second collection root', 4, [$genre]);
        $analyzer = FakeAudioAnalyzer::ready();
        $this->app->instance(AudioAnalyzer::class, $analyzer);
        $profile = app(AudioAnalysisProfileRegistry::class)->resolve($analyzer->health->profile);
        $baselineTrack = Track::query()
            ->whereHas(
                'mediaFile',
                fn ($query) => $query->where('library_root_id', $firstRoot->id),
            )
            ->with('mediaFile')
            ->firstOrFail();
        $artifact = AudioAnalysisArtifact::create([
            'audio_analysis_profile_id' => $profile->id,
            'content_fingerprint' => $baselineTrack->mediaFile->content_fingerprint,
            'content_fingerprint_version' => 1,
            'features' => ['bpm' => 120],
            'embedding' => [1, 0, 0],
            'runtime_ms' => 100,
            'windows_analyzed' => 1,
        ]);
        $reusableTargetTrack = Track::query()
            ->whereHas(
                'mediaFile',
                fn ($query) => $query->where('library_root_id', $secondRoot->id),
            )
            ->with('mediaFile')
            ->firstOrFail();
        AudioAnalysisArtifact::create([
            'audio_analysis_profile_id' => $profile->id,
            'content_fingerprint' => $reusableTargetTrack->mediaFile->content_fingerprint,
            'content_fingerprint_version' => 1,
            'features' => ['bpm' => 90],
            'embedding' => [0, 1, 0],
            'runtime_ms' => 80,
            'windows_analyzed' => 1,
        ]);
        $baselineRun = AudioAnalysisRun::create([
            'audio_analysis_profile_id' => $profile->id,
            'phase' => 'analysis',
            'status' => 'completed',
            'selection_seed' => fake()->uuid(),
            'requested_track_count' => 1,
            'selected_track_count' => 1,
        ]);
        $baselineRun->items()->create([
            'track_id' => $baselineTrack->id,
            'library_root_id' => $firstRoot->id,
            'audio_analysis_artifact_id' => $artifact->id,
            'content_fingerprint' => $baselineTrack->mediaFile->content_fingerprint,
            'content_fingerprint_version' => 1,
            'position' => 0,
            'status' => 'completed',
        ]);
        ApplicationSetting::current()->update([
            'audio_intelligence_enabled' => true,
        ]);

        $response = $this->postJson('/api/settings/audio-intelligence/collections', [
            'libraryRootId' => $secondRoot->id,
        ])
            ->assertAccepted()
            ->assertJsonPath('latestCollectionRun.kind', 'collection')
            ->assertJsonPath('latestCollectionRun.libraryRoot.id', $secondRoot->id)
            ->assertJsonPath('latestCollectionRun.requestedTrackCount', 4)
            ->assertJsonPath('latestCollectionRun.summary.mode', 'collection')
            ->assertJsonPath('collectionRuns.0.libraryRoot.id', $secondRoot->id)
            ->assertJsonPath('activeRun.kind', 'collection');

        $run = AudioAnalysisRun::findOrFail($response->json('latestCollectionRun.id'));
        $preparation = new PrepareAudioAnalysisRun($run->id);
        $fingerprinter = new FakeAudioContentFingerprinter();
        $planner = app(\App\Music\Intelligence\AudioAnalysisRunPlanner::class);
        $this->postJson("/api/settings/audio-intelligence/runs/{$run->id}/pause")
            ->assertOk();
        $preparation->handle($fingerprinter, new LibraryPathGuard(), $planner);

        $run->refresh();
        $this->assertSame('paused', $run->status);
        $this->assertSame(4, $run->items()->count());
        $this->assertSame(1, $run->items()->where('status', 'reused')->count());
        $this->assertFalse($run->summary['candidatesEnumerated']);

        $this->postJson("/api/settings/audio-intelligence/runs/{$run->id}/resume")
            ->assertAccepted()
            ->assertJsonPath('latestCollectionRun.status', 'fingerprinting');
        $preparation->handle($fingerprinter, new LibraryPathGuard(), $planner);

        $run->refresh();
        $this->assertSame('prepared', $run->status);
        $this->assertSame(4, $run->selected_track_count);
        $this->assertSame(1, $run->summary['reusedTrackCount']);
        $this->assertTrue($run->summary['candidatesEnumerated']);
        $this->assertSame(0, $fingerprinter->calls);
        $this->assertSame(
            [$secondRoot->id],
            $run->items()->distinct()->pluck('library_root_id')->all(),
        );

        $planner->populateCollectionRun($run);
        $this->assertSame(4, $run->items()->count());

        $this->postJson("/api/settings/audio-intelligence/runs/{$run->id}/start")
            ->assertAccepted()
            ->assertJsonPath('latestCollectionRun.status', 'queued');
        $this->postJson("/api/settings/audio-intelligence/runs/{$run->id}/pause")
            ->assertOk()
            ->assertJsonPath(
                'latestCollectionRun.pauseRequestedAt',
                fn (mixed $value): bool => is_string($value),
            );

        (new RunAudioAnalysis($run->id))
            ->handle(
                $analyzer,
                app(AudioVectorIndex::class),
                new LibraryPathGuard(),
            );

        $run->refresh();
        $this->assertSame('paused', $run->status);
        $this->assertSame(3, $run->items()->where('status', 'queued')->count());
        $this->assertSame(1, $run->items()->where('status', 'reused')->count());
        $this->assertSame([], $analyzer->requests);

        $this->postJson("/api/settings/audio-intelligence/runs/{$run->id}/resume")
            ->assertAccepted()
            ->assertJsonPath('latestCollectionRun.status', 'queued')
            ->assertJsonPath('latestCollectionRun.pauseRequestedAt', null);
    }

    /**
     * @param  list<Genre>  $genres
     */
    private function createCatalog(
        Library $library,
        string $name,
        int $trackCount,
        array $genres,
        bool $fingerprinted = true,
    ): LibraryRoot {
        $root = $library->roots()->create([
            'name' => $name,
            'path' => "D:/{$name}",
            'path_hash' => hash('sha256', mb_strtolower($name)),
            'enabled' => true,
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'title' => "{$name} Album",
            'sort_title' => "{$name} Album",
            'relative_path' => "{$name}/Album",
            'relative_path_hash' => hash('sha256', mb_strtolower("{$name}/album")),
        ]);

        foreach (range(1, $trackCount) as $position) {
            $relativePath = "{$name}/Album/track-{$position}.mp3";
            $mediaFile = MediaFile::create([
                'library_root_id' => $root->id,
                'album_id' => $album->id,
                'relative_path' => $relativePath,
                'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
                'file_size' => 1000 + $position,
                'modified_at' => now(),
                'last_seen_at' => now(),
                'content_fingerprint' => $fingerprinted
                    ? hash('sha256', "{$name}:audio:{$position}")
                    : null,
                'content_fingerprint_version' => $fingerprinted ? 1 : null,
            ]);
            $track = Track::create([
                'album_id' => $album->id,
                'media_file_id' => $mediaFile->id,
                'title' => "{$name} Track {$position}",
                'sort_title' => "{$name} Track {$position}",
            ]);
            $track->genres()->attach($genres[($position - 1) % count($genres)]);
        }

        return $root;
    }
}
