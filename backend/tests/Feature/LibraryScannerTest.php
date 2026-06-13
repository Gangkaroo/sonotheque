<?php

namespace Tests\Feature;

use App\Enums\ArtworkSource;
use App\Enums\MediaFileStatus;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanLibraryRoot;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Artwork;
use App\Models\Genre;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\ScanRun;
use App\Models\Track;
use App\Music\Artwork\EmbeddedArtwork;
use App\Music\Scanning\AudioMetadata;
use App\Music\Scanning\AudioMetadataReader;
use App\Music\Scanning\LibraryScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

        Storage::fake('artwork');
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

    public function test_scanner_caches_a_configured_folder_cover_on_an_unchanged_album(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'fake audio data');
        $root = $this->createRoot();

        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));
        $this->assertNull(Album::sole()->artwork_id);

        $this->createCover($albumPath.DIRECTORY_SEPARATOR.'cover.jpg', 640, 320);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $scan = $this->createScan($root);
        $this->app->make(LibraryScanner::class)->scan($scan);

        $artwork = Artwork::sole();
        $album = Album::with('artwork')->sole();

        $this->assertSame($artwork->id, $album->artwork->id);
        $this->assertSame(ArtworkSource::Folder, $artwork->source_type);
        $this->assertSame('cover.jpg', $artwork->source_relative_path);
        $this->assertSame(640, $artwork->width);
        $this->assertSame(320, $artwork->height);
        $this->assertSame(1, $this->metadataReader->calls);
        $this->assertSame(0, $scan->fresh()->files_updated);
        Storage::disk('artwork')->assertExists($artwork->cache_path);
        Storage::disk('artwork')->assertExists($artwork->thumbnail_path);

        [$thumbnailWidth, $thumbnailHeight, $thumbnailType] = getimagesize(
            Storage::disk('artwork')->path($artwork->thumbnail_path),
        );

        $this->assertSame(320, $thumbnailWidth);
        $this->assertSame(160, $thumbnailHeight);
        $this->assertSame(IMAGETYPE_WEBP, $thumbnailType);
    }

    public function test_invalid_folder_cover_is_a_nonfatal_scan_warning(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'fake audio data');
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'cover.jpg', 'not an image');
        $root = $this->createRoot();
        $scan = $this->createScan($root);

        $this->app->make(LibraryScanner::class)->scan($scan);

        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status);
        $this->assertSame(2, $scan->fresh()->warning_count);
        $this->assertSame(0, $scan->fresh()->error_count);
        $this->assertDatabaseCount(Artwork::class, 0);
    }

    public function test_scanner_uses_embedded_artwork_when_the_folder_cover_is_missing(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'fake audio data');
        $this->metadataReader = new FakeAudioMetadataReader(new AudioMetadata(
            title: 'Human Behaviour',
            album: 'Debut',
            albumArtist: 'Björk',
            artists: ['Björk'],
            embeddedArtwork: new EmbeddedArtwork($this->createCoverBytes(500, 500), 'image/jpeg'),
        ));
        $this->app->instance(AudioMetadataReader::class, $this->metadataReader);

        $this->app->make(LibraryScanner::class)->scan($this->createScan($this->createRoot()));

        $artwork = Artwork::sole();

        $this->assertSame(ArtworkSource::Embedded, $artwork->source_type);
        $this->assertNull($artwork->source_relative_path);
        $this->assertSame($artwork->id, Album::sole()->artwork_id);
        Storage::disk('artwork')->assertExists($artwork->cache_path);
        Storage::disk('artwork')->assertExists($artwork->thumbnail_path);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $this->app->make(LibraryScanner::class)->scan($this->createScan(LibraryRoot::sole()));

        $this->assertSame(1, $this->metadataReader->calls);
        $this->assertDatabaseCount(Artwork::class, 1);
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

    private function createCover(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 35, 75, 120);
        imagefill($image, 0, 0, $background);
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }

    private function createCoverBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 120, 60, 35);
        imagefill($image, 0, 0, $background);
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return is_string($bytes) ? $bytes : '';
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
