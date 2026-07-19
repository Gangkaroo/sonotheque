<?php

namespace Tests\Feature;

use App\Jobs\PrepareAudioIntelligencePilot;
use App\Jobs\RunAudioIntelligencePilot;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\AudioAnalysisRun;
use App\Models\Genre;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Track;
use App\Music\Intelligence\AudioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Fakes\FakeAudioAnalyzer;

class AudioIntelligenceSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_audio_intelligence_is_disabled_and_unprovisioned_by_default(): void
    {
        $this->getJson('/api/settings/audio-intelligence')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('sampleSize', 200)
            ->assertJsonPath('eligibleTrackCount', 0)
            ->assertJsonPath('fingerprintedTrackCount', 0)
            ->assertJsonPath('analyzerStatus', 'not_configured')
            ->assertJsonPath('analyzer.status', 'not_configured')
            ->assertJsonPath('latestPilot', null);

        $settings = ApplicationSetting::current();
        $this->assertFalse($settings->audio_intelligence_enabled);
        $this->assertSame(200, $settings->audio_intelligence_sample_size);
        $this->assertDatabaseCount('audio_analysis_runs', 0);
        $this->assertDatabaseCount('audio_analysis_run_items', 0);
    }

    public function test_settings_require_explicit_valid_opt_in_values(): void
    {
        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => true,
            'sampleSize' => 49,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('sampleSize');

        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => 'yes',
            'sampleSize' => 200,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');

        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => true,
            'sampleSize' => 500,
        ])->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('sampleSize', 500)
            ->assertJsonPath('analyzerStatus', 'not_configured');
    }

    public function test_pilot_preparation_requires_opt_in_and_dispatches_no_job(): void
    {
        Queue::fake();

        $this->postJson('/api/settings/audio-intelligence/pilots')
            ->assertStatus(409);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('audio_analysis_runs', 0);
    }

    public function test_it_queues_a_diverse_catalog_sample_for_bounded_fingerprint_preparation(): void
    {
        Queue::fake();
        $library = Library::create(['name' => 'Pilot library']);
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
            'sampleSize' => 50,
        ])->assertOk();

        $response = $this->postJson('/api/settings/audio-intelligence/pilots')
            ->assertAccepted()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('sampleSize', 50)
            ->assertJsonPath('eligibleTrackCount', 64)
            ->assertJsonPath('fingerprintedTrackCount', 60)
            ->assertJsonPath('analyzerStatus', 'not_configured')
            ->assertJsonPath('latestPilot.phase', 'preparation')
            ->assertJsonPath('latestPilot.status', 'fingerprinting')
            ->assertJsonPath('latestPilot.requestedTrackCount', 50)
            ->assertJsonPath('latestPilot.selectedTrackCount', 0)
            ->assertJsonPath('latestPilot.summary.eligibleRootCount', 3)
            ->assertJsonPath('latestPilot.summary.candidateTrackCount', 60)
            ->assertJsonPath('latestPilot.summary.candidateRootCount', 3)
            ->assertJsonPath('latestPilot.summary.candidateGenreCount', 3);

        $runId = $response->json('latestPilot.id');
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
            PrepareAudioIntelligencePilot::class,
            fn (PrepareAudioIntelligencePilot $job): bool => $job->audioAnalysisRunId === $runId,
        );
        Queue::assertNotPushed(RunAudioIntelligencePilot::class);
    }

    public function test_prepared_pilot_starts_with_a_ready_analyzer(): void
    {
        Queue::fake();
        $library = Library::create(['name' => 'Pilot library']);
        $genre = Genre::create(['name' => 'Ambient']);
        $this->createCatalog($library, 'First', 50, [$genre]);
        ApplicationSetting::current()->update([
            'audio_intelligence_enabled' => true,
            'audio_intelligence_sample_size' => 50,
        ]);
        $run = app(\App\Music\Intelligence\AudioIntelligencePilotSampler::class)->prepare(50);
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
        $this->postJson("/api/settings/audio-intelligence/pilots/{$run->id}/start")
            ->assertAccepted()
            ->assertJsonPath('analyzer.status', 'ready')
            ->assertJsonPath('latestPilot.status', 'queued')
            ->assertJsonPath('latestPilot.profile.modelName', 'Test model');

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
            RunAudioIntelligencePilot::class,
            fn (RunAudioIntelligencePilot $job): bool => $job->audioAnalysisRunId === $run->id,
        );
        $this->postJson("/api/settings/audio-intelligence/pilots/{$run->id}/resume")
            ->assertStatus(409);

        $this->postJson("/api/settings/audio-intelligence/pilots/{$run->id}/cancel")
            ->assertOk()
            ->assertJsonPath('latestPilot.status', 'queued')
            ->assertJsonPath('latestPilot.cancelRequestedAt', fn (mixed $value): bool => is_string($value));
        $this->assertNotNull($run->fresh()->cancel_requested_at);

        $run->update(['status' => 'cancelled', 'finished_at' => now()]);
        $run->items()->update(['status' => 'cancelled']);
        $this->postJson("/api/settings/audio-intelligence/pilots/{$run->id}/resume")
            ->assertAccepted()
            ->assertJsonPath('latestPilot.status', 'queued')
            ->assertJsonPath('latestPilot.cancelRequestedAt', null)
            ->assertJsonPath('latestPilot.resumable', false);
        $this->assertSame(
            50,
            $run->items()->where('status', 'queued')->count(),
        );
        Queue::assertPushed(RunAudioIntelligencePilot::class, 2);
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
