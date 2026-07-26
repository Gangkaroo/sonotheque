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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlbumPlaylistExportApiTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();

        $this->musicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'album-playlist-export-'.Str::uuid();
        File::ensureDirectoryExists($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'Disc 2');

        $library = Library::create(['name' => 'Test']);
        $root = $library->roots()->create([
            'name' => 'Music Archive',
            'path' => $this->musicPath,
            'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $this->musicPath))),
            'enabled' => true,
        ]);
        $artist = Artist::create([
            'name' => 'Album Artist',
            'sort_name' => 'Album Artist',
            'browse_initial' => 'A',
        ]);
        $this->album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Album Title',
            'sort_title' => 'Album Title',
            'relative_path' => 'Artist/Album',
            'relative_path_hash' => hash('sha256', 'artist/album'),
        ]);

        $this->createTrack($root, $artist, 'Disc 2/02.mp3', 'Second Track', 2, 2, 125_900);
        $this->createTrack($root, $artist, '01.mp3', 'First Track', 1, 1, 180_000);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->musicPath);

        parent::tearDown();
    }

    public function test_it_saves_an_m3u8_playlist_beside_the_album_with_a_prefilled_name(): void
    {
        $this->getJson("/api/albums/{$this->album->id}/playlist-export")
            ->assertOk()
            ->assertJsonPath('defaultFormat', 'm3u8')
            ->assertJsonPath('defaultFilename', 'Album Artist - Album Title.m3u8')
            ->assertJsonPath('directory.libraryRoot', 'Music Archive')
            ->assertJsonPath('directory.relativePath', 'Artist/Album');

        $this->postJson("/api/albums/{$this->album->id}/playlist-export", [
            'format' => 'm3u8',
            'filename' => 'Album Artist - Album Title.m3u8',
        ])
            ->assertOk()
            ->assertJsonPath('trackCount', 2)
            ->assertJsonPath('relativePath', 'Artist/Album/Album Artist - Album Title.m3u8');

        $playlist = $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'
            .DIRECTORY_SEPARATOR.'Album Artist - Album Title.m3u8';
        $this->assertFileExists($playlist);
        $this->assertSame(
            "01.mp3\r\nDisc 2/02.mp3\r\n",
            file_get_contents($playlist),
        );
    }

    public function test_m3u_uses_a_utf8_bom_and_requires_explicit_overwrite(): void
    {
        $payload = [
            'format' => 'm3u',
            'filename' => 'Album Artist - Album Title.m3u',
        ];

        $this->postJson("/api/albums/{$this->album->id}/playlist-export", $payload)
            ->assertOk();
        $this->postJson("/api/albums/{$this->album->id}/playlist-export", $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'A playlist with this name already exists.');
        $this->postJson("/api/albums/{$this->album->id}/playlist-export", [
            ...$payload,
            'overwrite' => true,
        ])->assertOk();

        $playlist = $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'
            .DIRECTORY_SEPARATOR.'Album Artist - Album Title.m3u';
        $this->assertStringStartsWith("\xEF\xBB\xBF01.mp3\r\n", (string) file_get_contents($playlist));
    }

    public function test_it_rejects_unsafe_or_mismatched_playlist_filenames(): void
    {
        $this->postJson("/api/albums/{$this->album->id}/playlist-export", [
            'format' => 'm3u8',
            'filename' => '../outside.m3u8',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'The playlist filename contains unsupported characters.',
            );

        $this->postJson("/api/albums/{$this->album->id}/playlist-export", [
            'format' => 'm3u8',
            'filename' => 'Wrong extension.m3u',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The playlist filename must end in .m3u8.');

        $this->assertFileDoesNotExist(
            $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'outside.m3u8',
        );
    }

    private function createTrack(
        LibraryRoot $root,
        Artist $artist,
        string $albumRelativeFile,
        string $title,
        int $disc,
        int $position,
        int $duration,
    ): void {
        $relativePath = 'Artist/Album/'.$albumRelativeFile;
        File::put(
            $this->musicPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath),
            'audio',
        );
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $this->album->id,
            'relative_path' => $relativePath,
            'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
            'file_size' => 5,
            'modified_at' => now(),
            'status' => MediaFileStatus::Available,
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $this->album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $title,
            'sort_title' => $title,
            'disc_number' => $disc,
            'track_number' => $position,
            'duration_ms' => $duration,
        ]);
        $track->artists()->attach($artist->id, [
            'role' => 'primary',
            'position' => 0,
        ]);
    }
}
