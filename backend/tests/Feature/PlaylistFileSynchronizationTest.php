<?php

namespace Tests\Feature;

use App\Enums\MediaFileStatus;
use App\Jobs\SynchronizePlaylistFile;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistExportLocation;
use App\Models\PlaylistFolder;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlaylistFileSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    private string $basePath;

    private string $exportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'playlist-sync-'.Str::uuid();
        $this->exportPath = $this->basePath.DIRECTORY_SEPARATOR.'exports';
        File::ensureDirectoryExists($this->exportPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_synchronizes_nested_playlist_folders_and_removes_the_old_file_after_renaming(): void
    {
        $track = $this->createTrack();
        $parent = PlaylistFolder::create(['name' => 'Road:Trips']);
        $folder = PlaylistFolder::create([
            'parent_id' => $parent->id,
            'name' => 'Night/Drives',
        ]);
        $playlist = Playlist::create([
            'playlist_folder_id' => $folder->id,
            'name' => 'Quiet * Songs',
        ]);
        $playlist->items()->create(['track_id' => $track->id, 'position' => 0]);
        $location = $this->createLocation();
        ApplicationSetting::current()->update([
            'playlist_export_format' => 'm3u8',
            'synchronize_playlists_to_files' => true,
        ]);
        $playlist->update(['playlist_export_sync_pending_at' => now()]);

        $this->runSynchronization($playlist);

        $relativePath = 'Road：Trips/Night／Drives/Quiet - Songs.m3u8';
        $firstPath = $this->absoluteExportPath($relativePath);
        $this->assertFileExists($firstPath);
        $this->assertStringContainsString('Artist/Album/01.mp3', file_get_contents($firstPath));
        $playlist->refresh();
        $this->assertSame($location->id, $playlist->playlist_export_location_id);
        $this->assertSame($relativePath, $playlist->playlist_export_relative_path);
        $this->assertNull($playlist->playlist_export_sync_pending_at);
        $this->assertNull($playlist->playlist_export_sync_error);

        $playlist->update(['name' => 'Quiet Songs']);
        $folder->update(['name' => 'After Dark']);
        $this->runSynchronization($playlist);

        $this->assertFileDoesNotExist($firstPath);
        $this->assertFileExists(
            $this->absoluteExportPath('Road：Trips/After Dark/Quiet Songs.m3u8'),
        );
    }

    public function test_enabling_synchronization_queues_existing_playlists(): void
    {
        Queue::fake();
        $this->createLocation();
        $playlist = Playlist::create(['name' => 'Existing']);

        $this->patchJson('/api/settings/playlist-exports', [
            'defaultFormat' => 'm3u8',
            'synchronizePlaylists' => true,
        ])->assertOk()
            ->assertJsonPath('synchronizePlaylists', true)
            ->assertJsonPath('synchronization.syncedCount', 0)
            ->assertJsonPath('synchronization.pendingCount', 1);

        $this->assertNotNull($playlist->refresh()->playlist_export_sync_pending_at);

        Queue::assertPushed(
            SynchronizePlaylistFile::class,
            fn (SynchronizePlaylistFile $job): bool => $job->playlistId === $playlist->id,
        );
    }

    public function test_playlist_mutations_queue_synchronization_when_enabled(): void
    {
        Queue::fake();
        $track = $this->createTrack();
        $folder = PlaylistFolder::create(['name' => 'Folder']);
        $this->createLocation();
        ApplicationSetting::current()->update([
            'synchronize_playlists_to_files' => true,
        ]);

        $playlistId = $this->postJson('/api/playlists', [
            'name' => 'Mutable',
            'folderId' => $folder->id,
            'trackIds' => [$track->id],
        ])->assertCreated()->json('id');

        $this->patchJson("/api/playlists/{$playlistId}", [
            'name' => 'Renamed',
        ])->assertOk();
        $this->patchJson("/api/playlist-folders/{$folder->id}", [
            'name' => 'Renamed folder',
        ])->assertOk();

        Queue::assertPushed(
            SynchronizePlaylistFile::class,
            fn (SynchronizePlaylistFile $job): bool => $job->playlistId === $playlistId,
        );
    }

    private function runSynchronization(Playlist $playlist): void
    {
        app()->call([new SynchronizePlaylistFile($playlist->id), 'handle']);
    }

    private function createLocation(): PlaylistExportLocation
    {
        return PlaylistExportLocation::create([
            'name' => 'Default',
            'path' => $this->exportPath,
            'path_hash' => hash(
                'sha256',
                PHP_OS_FAMILY === 'Windows'
                    ? mb_strtolower(str_replace('\\', '/', $this->exportPath))
                    : str_replace('\\', '/', $this->exportPath),
            ),
            'is_default' => true,
        ]);
    }

    private function createTrack(): Track
    {
        $library = Library::create(['name' => 'Test']);
        $rootPath = $this->basePath.DIRECTORY_SEPARATOR.'music';
        File::ensureDirectoryExists($rootPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album');
        File::put(
            $rootPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'01.mp3',
            'audio',
        );
        $root = $library->roots()->create([
            'name' => 'Music',
            'path' => $rootPath,
            'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $rootPath))),
            'enabled' => true,
        ]);
        $artist = Artist::create([
            'name' => 'Artist',
            'sort_name' => 'Artist',
            'browse_initial' => 'A',
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Album',
            'sort_title' => 'Album',
            'relative_path' => 'Artist/Album',
            'relative_path_hash' => hash('sha256', mb_strtolower('Artist/Album')),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => 'Artist/Album/01.mp3',
            'relative_path_hash' => hash('sha256', mb_strtolower('Artist/Album/01.mp3')),
            'file_size' => 5,
            'modified_at' => now(),
            'status' => MediaFileStatus::Available,
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Track',
            'sort_title' => 'Track',
        ]);
        $track->artists()->attach($artist->id, ['role' => 'primary', 'position' => 0]);

        return $track;
    }

    private function absoluteExportPath(string $relativePath): string
    {
        return $this->exportPath.DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath,
        );
    }
}
