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
use Tests\TestCase;

class AudioStreamingApiTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->musicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'music-stream-'.Str::uuid();
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album', recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->musicPath);

        parent::tearDown();
    }

    public function test_it_streams_a_complete_audio_file(): void
    {
        $track = $this->createTrack('0123456789');

        $response = $this->get("/api/tracks/{$track->id}/stream");

        $response->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Length', '10')
            ->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertSame('0123456789', $response->streamedContent());
    }

    public function test_it_streams_requested_byte_ranges(): void
    {
        $track = $this->createTrack('0123456789');

        $bounded = $this->get("/api/tracks/{$track->id}/stream", ['Range' => 'bytes=2-5']);
        $bounded->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4');
        $this->assertSame('2345', $bounded->streamedContent());

        $suffix = $this->get("/api/tracks/{$track->id}/stream", ['Range' => 'bytes=-3']);
        $suffix->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 7-9/10');
        $this->assertSame('789', $suffix->streamedContent());
    }

    public function test_it_returns_open_ended_ranges_as_complete_bounded_chunks(): void
    {
        config(['sonotheque.audio_stream_open_ended_range_bytes' => 4]);
        $track = $this->createTrack('0123456789');

        $response = $this->get("/api/tracks/{$track->id}/stream", ['Range' => 'bytes=2-']);

        $response->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4');
        $this->assertSame('2345', $response->streamedContent());
    }

    public function test_it_rejects_unsatisfiable_and_multiple_ranges(): void
    {
        $track = $this->createTrack('0123456789');

        $this->get("/api/tracks/{$track->id}/stream", ['Range' => 'bytes=20-'])
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');

        $this->get("/api/tracks/{$track->id}/stream", ['Range' => 'bytes=0-1,3-4'])
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');
    }

    public function test_it_hides_disabled_unavailable_and_unsafe_files(): void
    {
        $disabled = $this->createTrack('disabled', enabled: false);
        $this->get("/api/tracks/{$disabled->id}/stream")->assertNotFound();

        $missing = $this->createTrack('missing', status: MediaFileStatus::Missing);
        $this->get("/api/tracks/{$missing->id}/stream")->assertNotFound();

        $unsafe = $this->createTrack('unsafe');
        $unsafe->mediaFile->update(['relative_path' => '../outside.mp3']);
        $this->get("/api/tracks/{$unsafe->id}/stream")->assertNotFound();
    }

    private function createTrack(
        string $contents,
        bool $enabled = true,
        MediaFileStatus $status = MediaFileStatus::Available,
    ): Track {
        $artist = Artist::create([
            'name' => 'Artist '.Str::uuid(),
            'sort_name' => 'Artist',
            'browse_initial' => 'A',
        ]);
        $root = $this->createRoot($enabled);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Album '.Str::uuid(),
            'sort_title' => 'Album',
            'relative_path' => 'Artist/Album',
            'relative_path_hash' => hash('sha256', Str::uuid()),
        ]);
        $fileName = Str::uuid().'.mp3';
        $relativePath = 'Artist/Album/'.$fileName;
        file_put_contents($this->musicPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $contents);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => $relativePath,
            'relative_path_hash' => hash('sha256', $relativePath),
            'file_size' => strlen($contents),
            'modified_at' => now(),
            'mime_type' => 'audio/mpeg',
            'status' => $status,
            'last_seen_at' => now(),
        ]);

        return Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Track',
            'sort_title' => 'Track',
        ])->load('mediaFile');
    }

    private function createRoot(bool $enabled): LibraryRoot
    {
        return Library::create(['name' => 'Library '.Str::uuid()])->roots()->create([
            'name' => 'Root',
            'path' => $this->musicPath,
            'path_hash' => hash('sha256', Str::uuid()),
            'enabled' => $enabled,
        ]);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
