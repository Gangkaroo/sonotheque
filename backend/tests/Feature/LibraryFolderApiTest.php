<?php

namespace Tests\Feature;

use App\Enums\MediaFileStatus;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Album;
use App\Models\Artist;
use App\Models\FavoriteTrack;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Playlist;
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
            ->assertJsonPath('requiresConfirmation', false)
            ->assertJsonPath('tracks.0.title', 'First Track');
    }

    public function test_it_returns_only_the_count_when_a_folder_action_needs_confirmation(): void
    {
        $this->getJson(
            "/api/catalog/library-roots/{$this->root->id}/folder-tracks?path=Artist&confirmationThreshold=1",
        )
            ->assertOk()
            ->assertJsonPath('path', 'Artist')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('requiresConfirmation', true)
            ->assertJsonCount(0, 'tracks');
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

    public function test_it_renames_an_audio_file_without_replacing_its_catalog_identity(): void
    {
        $track = Track::query()->firstOrFail();
        $mediaFile = $track->mediaFile;
        $playlist = Playlist::create(['name' => 'Keep me']);
        $playlistItem = $playlist->items()->create(['track_id' => $track->id, 'position' => 0]);
        FavoriteTrack::create(['track_id' => $track->id]);

        $this->patchJson("/api/library_roots/{$this->root->id}/entries/rename", [
            'path' => 'Artist/Album/01.mp3',
            'name' => '01 - Renamed.mp3',
        ])
            ->assertOk()
            ->assertJsonPath('kind', 'file')
            ->assertJsonPath('oldPath', 'Artist/Album/01.mp3')
            ->assertJsonPath('newPath', 'Artist/Album/01 - Renamed.mp3')
            ->assertJsonPath('affectedFiles', 1)
            ->assertJsonPath('affectedTracks', 1);

        $this->assertFileDoesNotExist($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'01.mp3');
        $this->assertFileExists($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'01 - Renamed.mp3');
        $this->assertSame($mediaFile->id, $track->refresh()->media_file_id);
        $this->assertSame('Artist/Album/01 - Renamed.mp3', $mediaFile->refresh()->relative_path);
        $this->assertDatabaseHas('playlist_items', ['id' => $playlistItem->id, 'track_id' => $track->id]);
        $this->assertDatabaseHas('favorite_tracks', ['track_id' => $track->id]);

        $this->getJson("/api/catalog/library-roots/{$this->root->id}/folders?path=Artist%2FAlbum")
            ->assertOk()
            ->assertJsonPath('files.0.path', 'Artist/Album/01 - Renamed.mp3')
            ->assertJsonPath('files.0.track.id', $track->id);
    }

    public function test_it_renames_a_folder_and_updates_descendant_catalog_paths(): void
    {
        $track = Track::query()->firstOrFail();
        $mediaFileId = $track->media_file_id;
        $album = $track->album;
        $playlist = Playlist::create(['name' => 'Folder move']);
        $playlist->items()->create(['track_id' => $track->id, 'position' => 0]);

        $this->patchJson("/api/library_roots/{$this->root->id}/entries/rename", [
            'path' => 'Artist',
            'name' => 'Renamed Artist Folder',
        ])
            ->assertOk()
            ->assertJsonPath('kind', 'directory')
            ->assertJsonPath('newPath', 'Renamed Artist Folder')
            ->assertJsonPath('affectedFiles', 1)
            ->assertJsonPath('affectedTracks', 1);

        $this->assertDirectoryDoesNotExist($this->musicPath.DIRECTORY_SEPARATOR.'Artist');
        $this->assertDirectoryExists($this->musicPath.DIRECTORY_SEPARATOR.'Renamed Artist Folder');
        $this->assertSame($mediaFileId, $track->refresh()->media_file_id);
        $this->assertSame('Renamed Artist Folder/Album/01.mp3', $track->mediaFile->relative_path);
        $this->assertSame($album->id, $track->album_id);
        $this->assertSame('Renamed Artist Folder/Album', $album->refresh()->relative_path);
        $this->assertDatabaseHas('playlist_items', ['playlist_id' => $playlist->id, 'track_id' => $track->id]);
    }

    public function test_it_rejects_unsafe_or_conflicting_renames_and_renames_during_scans(): void
    {
        file_put_contents(
            $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'Existing.mp3',
            'audio',
        );

        $this->patchJson("/api/library_roots/{$this->root->id}/entries/rename", [
            'path' => 'Artist/Album/01.mp3',
            'name' => 'Existing.mp3',
        ])->assertConflict();

        $this->patchJson("/api/library_roots/{$this->root->id}/entries/rename", [
            'path' => 'Artist/Album/01.mp3',
            'name' => 'Renamed.flac',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'extension'));

        $this->root->scanRuns()->create([
            'status' => ScanStatus::Pending,
            'trigger' => ScanTrigger::Manual,
        ]);

        $this->patchJson("/api/library_roots/{$this->root->id}/entries/rename", [
            'path' => 'Artist/Album/01.mp3',
            'name' => 'Renamed.mp3',
        ])
            ->assertConflict()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'scanned'));
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
