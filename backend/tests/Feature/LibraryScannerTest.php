<?php

namespace Tests\Feature;

use App\Enums\MediaFileStatus;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanLibraryRoot;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\ScanRun;
use App\Models\Track;
use App\Music\Scanning\AudioMetadata;
use App\Music\Scanning\AudioMetadataReader;
use App\Music\Scanning\LibraryScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class LibraryScannerTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    private FakeAudioMetadataReader $metadataReader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->musicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'music-library-'.Str::uuid();
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut', recursive: true);

        $this->metadataReader = new FakeAudioMetadataReader(new AudioMetadata(
            title: 'Human Behaviour',
            album: 'Debut',
            albumArtist: 'Björk',
            artists: ['Björk'],
            genres: ['Electronic'],
            year: 1993,
            originalReleaseYear: 1993,
            trackNumber: 1,
            discNumber: 1,
            discTotal: 1,
            durationMs: 252000,
            mimeType: 'audio/mpeg',
            container: 'mp3',
            codec: 'mp3',
            bitrate: 320000,
            sampleRate: 44100,
            channels: 2,
            warnings: ['Test warning'],
            rawMetadata: ['fileformat' => 'mp3'],
        ));

        $this->app->instance(AudioMetadataReader::class, $this->metadataReader);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->removeDirectory($this->musicPath);

        parent::tearDown();
    }

    public function test_scanner_imports_metadata_and_skips_unchanged_files(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01 - Human Behaviour.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $root = $this->createRoot();

        $firstScan = $this->createScan($root);
        $this->app->make(LibraryScanner::class)->scan($firstScan);

        $this->assertSame(ScanStatus::Completed, $firstScan->fresh()->status);
        $this->assertSame(1, $firstScan->fresh()->files_added);
        $this->assertSame(1, $firstScan->fresh()->warning_count);
        $this->assertSame(1, $this->metadataReader->calls);
        $this->assertDatabaseHas(Artist::class, ['name' => 'Björk', 'browse_initial' => 'B']);
        $this->assertDatabaseHas(Album::class, ['title' => 'Debut', 'original_release_year' => 1993]);
        $this->assertDatabaseHas(Track::class, ['title' => 'Human Behaviour', 'track_number' => 1]);
        $this->assertDatabaseHas(Genre::class, ['name' => 'Electronic']);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Bjoerk/Debut/01 - Human Behaviour.mp3',
            'status' => MediaFileStatus::Available->value,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $secondScan = $this->createScan($root);
        $this->app->make(LibraryScanner::class)->scan($secondScan);

        $this->assertSame(ScanStatus::Completed, $secondScan->fresh()->status);
        $this->assertSame(0, $secondScan->fresh()->files_added);
        $this->assertSame(0, $secondScan->fresh()->files_updated);
        $this->assertSame(1, $secondScan->fresh()->files_processed);
        $this->assertSame(1, $this->metadataReader->calls);
    }

    public function test_scanner_marks_files_missing_when_they_disappear(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $root = $this->createRoot();

        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));
        unlink($trackPath);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());

        $scan = $this->createScan($root);
        $this->app->make(LibraryScanner::class)->scan($scan);

        $this->assertSame(1, $scan->fresh()->files_missing);
        $this->assertSame(MediaFileStatus::Missing, MediaFile::firstOrFail()->status);
    }

    public function test_malformed_track_does_not_replace_valid_album_metadata(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'valid fake audio');
        $root = $this->createRoot();

        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));

        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'02-broken.mp3', 'broken fake audio');
        $this->metadataReader->failOn('02-broken.mp3');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $scan = $this->createScan($root);
        $this->app->make(LibraryScanner::class)->scan($scan);

        $album = Album::with('primaryArtist')->sole();

        $this->assertSame('Björk', $album->primaryArtist->name);
        $this->assertSame(1993, $album->original_release_year);
        $this->assertSame(1, $scan->fresh()->error_count);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Bjoerk/Debut/02-broken.mp3',
            'status' => MediaFileStatus::Error->value,
        ]);
    }

    public function test_scan_command_dispatches_a_database_scan_job(): void
    {
        Queue::fake();
        $root = $this->createRoot();

        $this->artisan('music:scan', ['root' => $root->id])
            ->expectsOutputToContain('queued.')
            ->assertSuccessful();

        $scanRun = ScanRun::sole();

        Queue::assertPushed(ScanLibraryRoot::class, fn (ScanLibraryRoot $job): bool => $job->scanRunId === $scanRun->id);
        $this->assertDatabaseHas(ScanRun::class, [
            'library_root_id' => $root->id,
            'status' => ScanStatus::Pending->value,
            'trigger' => ScanTrigger::Manual->value,
        ]);
    }

    private function createRoot(): LibraryRoot
    {
        $library = Library::create(['name' => 'Test Library']);

        return $library->roots()->create([
            'name' => 'Test Root',
            'path' => $this->musicPath,
            'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $this->musicPath))),
            'cover_image_path' => 'cover.jpg',
        ]);
    }

    private function createScan(LibraryRoot $root): ScanRun
    {
        return $root->scanRuns()->create([
            'status' => ScanStatus::Pending,
            'trigger' => ScanTrigger::Manual,
        ]);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}

class FakeAudioMetadataReader implements AudioMetadataReader
{
    public int $calls = 0;

    /** @var list<string> */
    private array $failPaths = [];

    public function __construct(private readonly AudioMetadata $metadata) {}

    public function read(string $absolutePath): AudioMetadata
    {
        $this->calls++;

        foreach ($this->failPaths as $path) {
            if (str_contains($absolutePath, $path)) {
                throw new \RuntimeException('The test audio file is malformed.');
            }
        }

        return $this->metadata;
    }

    public function failOn(string $path): void
    {
        $this->failPaths[] = $path;
    }
}
