<?php

namespace Tests\Feature;

use App\Enums\ArtworkSource;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Artwork;
use App\Models\Library;
use App\Models\MediaFile;
use App\Music\Artwork\EmbeddedArtwork;
use App\Music\Scanning\AudioMetadata;
use App\Music\Scanning\AudioMetadataReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtworkApiTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->musicPath = storage_path('framework/testing/artwork-api-'.bin2hex(random_bytes(6)));
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'Cover', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->musicPath);
        parent::tearDown();
    }

    public function test_album_original_streams_the_folder_source_and_cleanup_removes_all_legacy_originals(): void
    {
        Storage::fake('artwork');
        Storage::disk('artwork')->put('originals/example.jpg', 'cached duplicate');
        $sourcePath = $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'Cover'.DIRECTORY_SEPARATOR.'Front.jpg';
        file_put_contents($sourcePath, 'folder image bytes');
        $album = $this->createAlbum();
        $artwork = Artwork::create([
            'source_type' => ArtworkSource::Folder,
            'source_relative_path' => 'Cover/Front.jpg',
            'thumbnail_path' => 'thumbnails/example.webp',
            'mime_type' => 'image/jpeg',
            'width' => 1200,
            'height' => 1200,
            'checksum' => hash('sha256', 'folder image bytes'),
        ]);
        $album->update([
            'artwork_id' => $artwork->id,
            'artwork_source_type' => ArtworkSource::Folder,
            'artwork_source_relative_path' => 'Cover/Front.jpg',
        ]);

        $response = $this->get("/api/albums/{$album->id}/artwork/original")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(realpath($sourcePath), $response->baseResponse->getFile()->getRealPath());

        $this->artisan('music:artwork:remove-original-cache')->assertSuccessful();
        Storage::disk('artwork')->assertMissing('originals/example.jpg');
    }

    public function test_album_original_extracts_embedded_artwork_from_an_audio_file(): void
    {
        Storage::fake('artwork');
        $bytes = 'embedded image bytes';
        $album = $this->createAlbum();
        $artwork = Artwork::create([
            'source_type' => ArtworkSource::Embedded,
            'source_relative_path' => null,
            'thumbnail_path' => 'thumbnails/embedded.webp',
            'mime_type' => 'image/jpeg',
            'width' => 1200,
            'height' => 1200,
            'checksum' => hash('sha256', $bytes),
        ]);
        $album->update([
            'artwork_id' => $artwork->id,
            'artwork_source_type' => ArtworkSource::Embedded,
        ]);
        $audioPath = $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($audioPath, 'fake audio');
        MediaFile::create([
            'library_root_id' => $album->library_root_id,
            'album_id' => $album->id,
            'relative_path' => 'Artist/Album/01.mp3',
            'relative_path_hash' => hash('sha256', 'artist/album/01.mp3'),
            'file_size' => 10,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $this->app->instance(AudioMetadataReader::class, new class ($bytes) implements AudioMetadataReader {
            public function __construct(private readonly string $bytes)
            {
            }

            public function read(string $absolutePath): AudioMetadata
            {
                return new AudioMetadata(
                    embeddedArtwork: new EmbeddedArtwork($this->bytes, 'image/jpeg'),
                );
            }
        });

        $this->get("/api/albums/{$album->id}/artwork/original")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertContent($bytes);
    }

    private function createAlbum(): Album
    {
        $artist = Artist::create([
            'name' => 'Artist',
            'sort_name' => 'Artist',
            'browse_initial' => 'A',
        ]);
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => $this->musicPath,
            'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $this->musicPath))),
        ]);

        return Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Album',
            'sort_title' => 'Album',
            'relative_path' => 'Artist/Album',
            'relative_path_hash' => hash('sha256', 'artist/album'),
        ]);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($child) ? $this->deleteDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
