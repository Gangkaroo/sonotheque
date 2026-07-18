<?php

namespace Tests\Feature;

use App\Jobs\RunAudioIntelligencePilot;
use App\Models\Album;
use App\Models\AudioAnalysisProfile;
use App\Models\AudioAnalysisRun;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use App\Music\Intelligence\AudioAnalyzerResult;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Fakes\FakeAudioAnalyzer;
use Tests\TestCase;

class RunAudioIntelligencePilotTest extends TestCase
{
    use RefreshDatabase;

    private string $libraryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->libraryPath = storage_path('framework/testing/audio-intelligence-'.uniqid());
        File::ensureDirectoryExists($this->libraryPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->libraryPath);
        parent::tearDown();
    }

    public function test_job_persists_results_and_marks_changed_audio_stale(): void
    {
        config()->set('sonotheque.audio_intelligence.chunk_size', 1);

        $library = Library::create(['name' => 'Pilot']);
        $root = $library->roots()->create([
            'name' => 'Root',
            'path' => $this->libraryPath,
            'path_hash' => hash('sha256', mb_strtolower($this->libraryPath)),
            'enabled' => true,
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'title' => 'Album',
            'sort_title' => 'Album',
            'relative_path' => 'Album',
            'relative_path_hash' => hash('sha256', 'album'),
        ]);
        File::ensureDirectoryExists($this->libraryPath.'/Album');
        File::put($this->libraryPath.'/Album/one.mp3', 'audio one');
        File::put($this->libraryPath.'/Album/two.mp3', 'audio two');

        $firstTrack = $this->createTrack($root->id, $album, 'Album/one.mp3', str_repeat('1', 64));
        $secondTrack = $this->createTrack($root->id, $album, 'Album/two.mp3', str_repeat('2', 64));
        $profile = AudioAnalysisProfile::create([
            'profile_key' => 'test-analyzer',
            'protocol_version' => 1,
            'analyzer_name' => 'Test analyzer',
            'analyzer_version' => '1.0.0',
            'analyzer_license' => 'Test license',
            'model_name' => 'Test model',
            'model_version' => '1',
            'model_checksum' => str_repeat('a', 64),
            'model_license' => 'Test model license',
            'embedding_dimensions' => 3,
            'sample_rate' => 16000,
            'manifest' => [],
        ]);
        $run = AudioAnalysisRun::create([
            'audio_analysis_profile_id' => $profile->id,
            'status' => 'queued',
            'selection_seed' => fake()->uuid(),
            'requested_track_count' => 2,
            'selected_track_count' => 2,
            'summary' => [],
        ]);
        $firstItem = $run->items()->create([
            'track_id' => $firstTrack->id,
            'library_root_id' => $root->id,
            'content_fingerprint' => str_repeat('1', 64),
            'content_fingerprint_version' => 1,
            'position' => 0,
            'status' => 'queued',
        ]);
        $secondItem = $run->items()->create([
            'track_id' => $secondTrack->id,
            'library_root_id' => $root->id,
            'content_fingerprint' => str_repeat('3', 64),
            'content_fingerprint_version' => 1,
            'position' => 1,
            'status' => 'queued',
        ]);
        $analyzer = FakeAudioAnalyzer::ready();
        $analyzer->results = [
            new AudioAnalyzerResult(
                itemId: $firstItem->id,
                status: 'completed',
                features: ['bpm' => 120.0],
                embedding: [0.1, 0.2, 0.3],
                runtimeMs: 25,
                windowsAnalyzed: 1,
                timings: [
                    'decodeMs' => 5,
                    'featureExtractionMs' => 10,
                    'embeddingMs' => 8,
                ],
                hardware: ['system' => 'test'],
            ),
        ];

        (new RunAudioIntelligencePilot($run->id))->handle($analyzer, new LibraryPathGuard());

        $this->assertDatabaseHas('audio_analysis_run_items', [
            'id' => $firstItem->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('audio_analysis_run_items', [
            'id' => $secondItem->id,
            'status' => 'stale',
        ]);
        $this->assertDatabaseHas('audio_analysis_artifacts', [
            'content_fingerprint' => str_repeat('1', 64),
            'runtime_ms' => 25,
            'windows_analyzed' => 1,
        ]);
        $this->assertDatabaseHas('audio_analysis_artifacts', [
            'content_fingerprint' => str_repeat('1', 64),
            'timings->decodeMs' => 5,
        ]);
        $run->refresh();
        $this->assertSame('partial', $run->status);
        $this->assertSame(1, $run->summary['analyzedTrackCount']);
        $this->assertSame(1, $run->summary['staleTrackCount']);
        $this->assertSame(2, $run->summary['processedTrackCount']);
        $this->assertCount(1, $analyzer->requests);

        $secondRun = AudioAnalysisRun::create([
            'audio_analysis_profile_id' => $profile->id,
            'status' => 'queued',
            'selection_seed' => fake()->uuid(),
            'requested_track_count' => 1,
            'selected_track_count' => 1,
            'summary' => [],
        ]);
        $reusedItem = $secondRun->items()->create([
            'track_id' => $firstTrack->id,
            'library_root_id' => $root->id,
            'content_fingerprint' => str_repeat('1', 64),
            'content_fingerprint_version' => 1,
            'position' => 0,
            'status' => 'queued',
        ]);
        $reuseAnalyzer = FakeAudioAnalyzer::ready();

        (new RunAudioIntelligencePilot($secondRun->id))
            ->handle($reuseAnalyzer, new LibraryPathGuard());

        $reusedItem->refresh();
        $secondRun->refresh();
        $this->assertSame('reused', $reusedItem->status);
        $this->assertNotNull($reusedItem->audio_analysis_artifact_id);
        $this->assertSame('completed', $secondRun->status);
        $this->assertSame(1, $secondRun->summary['reusedTrackCount']);
        $this->assertSame([], $reuseAnalyzer->requests);
    }

    private function createTrack(int $rootId, Album $album, string $relativePath, string $fingerprint): Track
    {
        $mediaFile = MediaFile::create([
            'library_root_id' => $rootId,
            'album_id' => $album->id,
            'relative_path' => $relativePath,
            'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
            'file_size' => 100,
            'modified_at' => now(),
            'last_seen_at' => now(),
            'content_fingerprint' => $fingerprint,
            'content_fingerprint_version' => 1,
        ]);

        return Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => basename($relativePath),
            'sort_title' => basename($relativePath),
        ]);
    }
}
