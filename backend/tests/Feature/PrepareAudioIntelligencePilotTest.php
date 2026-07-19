<?php

namespace Tests\Feature;

use App\Jobs\PrepareAudioIntelligencePilot;
use App\Models\Album;
use App\Models\AudioAnalysisRun;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use App\Music\Intelligence\AudioIntelligencePilotSampler;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Fakes\FakeAudioContentFingerprinter;
use Tests\TestCase;

class PrepareAudioIntelligencePilotTest extends TestCase
{
    use RefreshDatabase;

    private string $libraryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->libraryPath = storage_path('framework/testing/pilot-preparation-'.uniqid());
        File::ensureDirectoryExists($this->libraryPath.'/Album');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->libraryPath);
        parent::tearDown();
    }

    public function test_it_reuses_fingerprints_and_processes_reserve_candidates_until_the_sample_is_full(): void
    {
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

        File::put($this->libraryPath.'/Album/existing.mp3', 'existing audio');
        File::put($this->libraryPath.'/Album/reserve.mp3', 'reserve audio');
        File::put($this->libraryPath.'/Album/unused.mp3', 'unused audio');

        $existing = $this->createTrack(
            $root->id,
            $album,
            'Album/existing.mp3',
            str_repeat('a', 64),
        );
        $missing = $this->createTrack($root->id, $album, 'Album/missing.mp3');
        $reserve = $this->createTrack($root->id, $album, 'Album/reserve.mp3');
        $unused = $this->createTrack($root->id, $album, 'Album/unused.mp3');
        $run = AudioAnalysisRun::create([
            'phase' => 'preparation',
            'status' => 'fingerprinting',
            'selection_seed' => fake()->uuid(),
            'requested_track_count' => 2,
            'selected_track_count' => 0,
            'summary' => ['candidateTrackCount' => 4],
        ]);

        foreach ([$existing, $missing, $reserve, $unused] as $position => $track) {
            $run->items()->create([
                'track_id' => $track->id,
                'library_root_id' => $root->id,
                'position' => $position,
                'status' => 'pending_fingerprint',
            ]);
        }

        $fingerprinter = new FakeAudioContentFingerprinter();
        (new PrepareAudioIntelligencePilot($run->id))->handle(
            $fingerprinter,
            new LibraryPathGuard(),
            app(AudioIntelligencePilotSampler::class),
        );

        $run->refresh();
        $this->assertSame('prepared', $run->status);
        $this->assertSame(2, $run->selected_track_count);
        $this->assertSame(2, $run->summary['fingerprintedTrackCount']);
        $this->assertSame(1, $run->summary['fingerprintFailedTrackCount']);
        $this->assertSame(3, $run->summary['processedFingerprintTrackCount']);
        $this->assertSame(1, $fingerprinter->calls);
        $this->assertSame(2, $run->items()->where('status', 'selected')->count());
        $this->assertSame(1, $run->items()->where('status', 'fingerprint_failed')->count());
        $this->assertSame(1, $run->items()->where('status', 'not_selected')->count());
        $this->assertSame(
            hash('sha256', 'reserve audio'),
            $reserve->mediaFile->fresh()->content_fingerprint,
        );
    }

    private function createTrack(
        int $rootId,
        Album $album,
        string $relativePath,
        ?string $fingerprint = null,
    ): Track {
        $absolutePath = $this->libraryPath.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $exists = File::exists($absolutePath);
        $mediaFile = MediaFile::create([
            'library_root_id' => $rootId,
            'album_id' => $album->id,
            'relative_path' => $relativePath,
            'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
            'file_size' => $exists ? File::size($absolutePath) : 100,
            'modified_at' => $exists
                ? now()->setTimestamp(File::lastModified($absolutePath))
                : now(),
            'last_seen_at' => now(),
            'content_fingerprint' => $fingerprint,
            'content_fingerprint_version' => $fingerprint === null ? null : 1,
        ]);

        return Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => basename($relativePath),
            'sort_title' => basename($relativePath),
        ]);
    }
}
