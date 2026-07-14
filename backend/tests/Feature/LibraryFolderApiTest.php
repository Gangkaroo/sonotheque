<?php

namespace Tests\Feature;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class LibraryFolderApiTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    private LibraryRoot $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->musicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'library-folder-'.Str::uuid();
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album', recursive: true);
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Other', recursive: true);
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Excluded', recursive: true);
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'$RECYCLE.BIN', recursive: true);
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'System Volume Information', recursive: true);
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'found.000', recursive: true);
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'.Spotlight-V100', recursive: true);
        file_put_contents($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'01.mp3', 'audio');
        file_put_contents($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'02.flac', 'audio');
        file_put_contents($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'notes.txt', 'hidden');

        $library = Library::create(['name' => 'Test']);
        $this->root = $library->roots()->create([
            'name' => 'Music',
            'path' => $this->musicPath,
            'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $this->musicPath))),
            'excluded_directories' => ['Excluded'],
        ]);
        $this->createIndexedTrack('Artist/Album/01.mp3');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->musicPath);

        parent::tearDown();
    }

    public function test_it_browses_root_relative_directories_and_enriches_indexed_audio_files(): void
    {
        $this->getJson("/api/catalog/library-roots/{$this->root->id}/folders")
            ->assertOk()
            ->assertJsonPath('path', null)
            ->assertJsonCount(1, 'directories')
            ->assertJsonPath('directories.0.path', 'Artist');

        $response = $this->getJson(
            "/api/catalog/library-roots/{$this->root->id}/folders?path=Artist%2FAlbum",
        )->assertOk();

        $response
            ->assertJsonPath('parentPath', 'Artist')
            ->assertJsonCount(3, 'breadcrumbs')
            ->assertJsonCount(2, 'files')
            ->assertJsonPath('files.0.path', 'Artist/Album/01.mp3')
            ->assertJsonPath('files.0.indexed', true)
            ->assertJsonPath('files.0.available', true)
            ->assertJsonPath('files.0.track.title', 'First Track')
            ->assertJsonPath('files.1.path', 'Artist/Album/02.flac')
            ->assertJsonPath('files.1.indexed', false)
            ->assertJsonPath('files.1.track', null);
    }

    public function test_it_returns_indexed_tracks_recursively_for_folder_actions(): void
    {
        $this->getJson(
            "/api/catalog/library-roots/{$this->root->id}/folder-tracks?path=Artist",
        )
            ->assertOk()
            ->assertJsonPath('path', 'Artist')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('tracks.0.title', 'First Track');
    }

    public function test_it_rejects_paths_outside_or_excluded_from_the_root(): void
    {
        $this->getJson(
            "/api/catalog/library-roots/{$this->root->id}/folders?path=..%2Foutside",
        )->assertUnprocessable();

        $this->getJson(
            "/api/catalog/library-roots/{$this->root->id}/folders?path=Excluded",
        )
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'excluded'));

        $this->getJson(
            "/api/catalog/library-roots/{$this->root->id}/folders?path=%24RECYCLE.BIN",
        )
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'excluded'));
    }

    private function createIndexedTrack(string $relativePath): void
    {
        $artist = Artist::create([
            'name' => 'Artist',
            'sort_name' => 'Artist',
            'browse_initial' => 'A',
        ]);
        $album = Album::create([
            'library_root_id' => $this->root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Album',
            'sort_title' => 'Album',
            'relative_path' => 'Artist/Album',
            'relative_path_hash' => $this->pathHash('Artist/Album'),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $this->root->id,
            'album_id' => $album->id,
            'relative_path' => $relativePath,
            'relative_path_hash' => $this->pathHash($relativePath),
            'file_size' => 5,
            'modified_at' => now(),
            'status' => MediaFileStatus::Available,
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'First Track',
            'sort_title' => 'First Track',
            'duration_ms' => 120000,
            'track_number' => 1,
            'disc_number' => 1,
        ]);
        $track->artists()->attach($artist->id, ['role' => 'primary', 'position' => 0]);
    }

    private function pathHash(string $path): string
    {
        return hash('sha256', mb_strtolower(str_replace('\\', '/', $path)));
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

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }
}
