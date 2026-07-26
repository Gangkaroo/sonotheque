<?php

namespace Tests\Feature;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistExportLocation;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomPlaylistExportApiTest extends TestCase
{
    use RefreshDatabase;

    private string $basePath;

    private string $exportPath;

    private Playlist $playlist;

    private PlaylistExportLocation $location;

    private LibraryRoot $firstRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'custom-playlist-export-'.Str::uuid();
        $this->exportPath = $this->basePath.DIRECTORY_SEPARATOR.'exports';
        File::ensureDirectoryExists($this->exportPath);

        $library = Library::create(['name' => 'Test']);
        $this->firstRoot = $this->createRoot($library, 'First root', 'music-one');
        $secondRoot = $this->createRoot($library, 'Second root', 'music-two');
        $firstTrack = $this->createTrack($this->firstRoot, 'Artist One', 'First', '01.mp3');
        $secondTrack = $this->createTrack($secondRoot, 'Artist Two', 'Second', '02.mp3');

        $this->playlist = Playlist::create(['name' => 'Road Trip']);
        $this->playlist->items()->create(['track_id' => $secondTrack->id, 'position' => 0]);
        $this->playlist->items()->create(['track_id' => $firstTrack->id, 'position' => 1]);
        $this->location = PlaylistExportLocation::create([
            'name' => 'Main playlists',
            'path' => $this->exportPath,
            'path_hash' => hash('sha256', str_replace('\\', '/', $this->exportPath)),
            'is_default' => true,
        ]);
        ApplicationSetting::current()->update(['playlist_export_format' => 'm3u']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_exports_playlist_items_in_order_using_paths_relative_to_the_destination(): void
    {
        $this->getJson("/api/playlists/{$this->playlist->id}/file-export")
            ->assertOk()
            ->assertJsonPath('defaultFormat', 'm3u')
            ->assertJsonPath('defaultFilename', 'Road Trip.m3u')
            ->assertJsonPath('defaultLocationId', $this->location->id)
            ->assertJsonPath('trackCount', 2);

        $this->postJson("/api/playlists/{$this->playlist->id}/file-export", [
            'locationId' => $this->location->id,
            'format' => 'm3u8',
            'filename' => 'Road Trip.m3u8',
        ])->assertOk()
            ->assertJsonPath('trackCount', 2)
            ->assertJsonPath('location.id', $this->location->id);

        $this->assertSame(
            "../music-two/Artist Two/Second/02.mp3\r\n"
            ."../music-one/Artist One/First/01.mp3\r\n",
            file_get_contents($this->exportPath.DIRECTORY_SEPARATOR.'Road Trip.m3u8'),
        );
    }

    public function test_it_exports_only_tracks_visible_in_the_selected_library_root_scope(): void
    {
        $query = '?libraryRoot='.$this->firstRoot->id;

        $this->getJson("/api/playlists/{$this->playlist->id}/file-export{$query}")
            ->assertOk()
            ->assertJsonPath('trackCount', 1);

        $this->postJson("/api/playlists/{$this->playlist->id}/file-export{$query}", [
            'locationId' => $this->location->id,
            'format' => 'm3u8',
            'filename' => 'First root.m3u8',
        ])->assertOk()
            ->assertJsonPath('trackCount', 1);

        $this->assertSame(
            "../music-one/Artist One/First/01.mp3\r\n",
            file_get_contents($this->exportPath.DIRECTORY_SEPARATOR.'First root.m3u8'),
        );
    }

    private function createRoot(Library $library, string $name, string $directory): LibraryRoot
    {
        $path = $this->basePath.DIRECTORY_SEPARATOR.$directory;
        File::ensureDirectoryExists($path);

        return $library->roots()->create([
            'name' => $name,
            'path' => $path,
            'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $path))),
            'enabled' => true,
        ]);
    }

    private function createTrack(
        LibraryRoot $root,
        string $artistName,
        string $albumTitle,
        string $filename,
    ): Track {
        $artist = Artist::create([
            'name' => $artistName,
            'sort_name' => $artistName,
            'browse_initial' => mb_substr($artistName, 0, 1),
        ]);
        $relativeDirectory = $artistName.'/'.$albumTitle;
        $relativePath = $relativeDirectory.'/'.$filename;
        File::ensureDirectoryExists(
            $root->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory),
        );
        File::put(
            $root->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath),
            'audio',
        );
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => $albumTitle,
            'sort_title' => $albumTitle,
            'relative_path' => $relativeDirectory,
            'relative_path_hash' => hash('sha256', mb_strtolower($relativeDirectory)),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => $relativePath,
            'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
            'file_size' => 5,
            'modified_at' => now(),
            'status' => MediaFileStatus::Available,
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $filename,
            'sort_title' => $filename,
        ]);
        $track->artists()->attach($artist->id, ['role' => 'primary', 'position' => 0]);

        return $track;
    }
}
