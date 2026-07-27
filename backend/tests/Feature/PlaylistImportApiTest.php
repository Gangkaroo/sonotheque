<?php

namespace Tests\Feature;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistFolder;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlaylistImportApiTest extends TestCase
{
    use RefreshDatabase;

    private string $basePath;

    private string $playlistPath;

    private LibraryRoot $firstRoot;

    private Track $firstTrack;

    private Track $secondTrack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'playlist-import-'.Str::uuid();
        $this->playlistPath = $this->basePath.DIRECTORY_SEPARATOR.'playlists';
        File::ensureDirectoryExists($this->playlistPath);

        $library = Library::create(['name' => 'Test']);
        $this->firstRoot = $this->createRoot($library, 'First root', 'music-one');
        $secondRoot = $this->createRoot($library, 'Second root', 'music-two');
        $this->firstTrack = $this->createTrack($this->firstRoot, 'Artist One', 'First', '01.mp3');
        $this->secondTrack = $this->createTrack($secondRoot, 'Artist Two', 'Second', '02.mp3');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_imports_relative_and_absolute_entries_in_order_and_reports_missing_files(): void
    {
        $folder = PlaylistFolder::create(['name' => 'Imported']);
        $playlistFile = $this->playlistPath.DIRECTORY_SEPARATOR.'Road Trip.m3u8';
        $secondPath = $this->absoluteTrackPath($this->secondTrack);
        File::put(
            $playlistFile,
            "#EXTM3U\r\n"
            ."#EXTINF:123,Ignored metadata\r\n"
            ."../music-one/Artist One/First/01.mp3\r\n"
            .$secondPath."\r\n"
            ."../music-one/Missing/03.mp3\r\n"
            ."../music-one/Artist One/First/01.mp3\r\n",
        );

        $this->postJson('/api/playlists/import', [
            'path' => $playlistFile,
            'name' => 'Road Trip',
            'folderId' => $folder->id,
        ])
            ->assertCreated()
            ->assertJsonPath('playlist.name', 'Road Trip')
            ->assertJsonPath('playlist.folder.id', $folder->id)
            ->assertJsonPath('playlist.trackCount', 3)
            ->assertJsonPath('totalEntries', 4)
            ->assertJsonPath('importedCount', 3)
            ->assertJsonPath('unresolvedCount', 1)
            ->assertJsonPath('warnings.0.line', 5)
            ->assertJsonPath('warnings.0.path', '../music-one/Missing/03.mp3');

        $playlist = Playlist::where('name', 'Road Trip')->sole();
        $this->assertSame(
            [$this->firstTrack->id, $this->secondTrack->id, $this->firstTrack->id],
            $playlist->items()->orderBy('position')->pluck('track_id')->all(),
        );
        $this->assertSame([0, 1, 2], $playlist->items()->orderBy('position')->pluck('position')->all());
    }

    public function test_it_supports_windows_1252_m3u_files_and_file_urls(): void
    {
        $track = $this->createTrack($this->firstRoot, 'Björk', 'Début', 'Human Behaviour.mp3');
        $absolutePath = str_replace('\\', '/', $this->absoluteTrackPath($track));
        $fileUrl = 'file:///'.ltrim($absolutePath, '/');
        $playlistFile = $this->playlistPath.DIRECTORY_SEPARATOR.'Legacy.m3u';
        File::put($playlistFile, mb_convert_encoding($fileUrl."\r\n", 'Windows-1252', 'UTF-8'));

        $this->postJson('/api/playlists/import', [
            'path' => $playlistFile,
            'name' => 'Legacy',
        ])
            ->assertCreated()
            ->assertJsonPath('playlist.trackCount', 1)
            ->assertJsonPath('unresolvedCount', 0);
    }

    public function test_it_rejects_non_playlist_files_without_creating_a_playlist(): void
    {
        $file = $this->playlistPath.DIRECTORY_SEPARATOR.'notes.txt';
        File::put($file, 'not a playlist');

        $this->postJson('/api/playlists/import', [
            'path' => $file,
            'name' => 'Notes',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('path');

        $this->assertDatabaseCount('playlists', 0);
    }

    public function test_folder_browser_lists_playlist_files_only_when_requested(): void
    {
        File::put($this->playlistPath.DIRECTORY_SEPARATOR.'First.m3u', 'track.mp3');
        File::put($this->playlistPath.DIRECTORY_SEPARATOR.'Second.m3u8', 'track.mp3');
        File::put($this->playlistPath.DIRECTORY_SEPARATOR.'Notes.txt', 'notes');

        $query = urlencode($this->playlistPath);

        $this->getJson("/api/folders?path={$query}")
            ->assertOk()
            ->assertJsonPath('files', []);

        $this->getJson("/api/folders?path={$query}&playlistFiles=1")
            ->assertOk()
            ->assertJsonCount(2, 'files')
            ->assertJsonPath('files.0.name', 'First.m3u')
            ->assertJsonPath('files.1.name', 'Second.m3u8');
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

    private function absoluteTrackPath(Track $track): string
    {
        $mediaFile = $track->mediaFile()->with('libraryRoot')->firstOrFail();

        return $mediaFile->libraryRoot->path
            .DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $mediaFile->relative_path);
    }
}
