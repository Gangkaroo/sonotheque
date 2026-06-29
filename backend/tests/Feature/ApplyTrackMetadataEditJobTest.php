<?php

namespace Tests\Feature;

use App\Jobs\ApplyTrackMetadataEdit;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\MetadataEditJob;
use App\Models\Track;
use App\Music\Metadata\TrackMetadataEditing;
use App\Music\Metadata\TrackMetadataWriter;
use App\Music\Scanning\AudioMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyTrackMetadataEditJobTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->musicPath = storage_path('framework/testing/metadata-edit-'.bin2hex(random_bytes(6)));
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->musicPath);
        parent::tearDown();
    }

    public function test_the_job_updates_the_file_fingerprint_catalog_and_status(): void
    {
        $writer = new FakeTrackMetadataWriter;
        $this->app->instance(TrackMetadataWriter::class, $writer);
        $track = $this->createTrack();
        $path = $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'track.mp3';
        file_put_contents($path, 'audio');
        $editing = $this->app->make(TrackMetadataEditing::class);
        $values = [
            'title' => 'Changed title',
            'artistNames' => ['Changed artist'],
            'composers' => ['Composer'],
            'performers' => ['Performer'],
            'comment' => 'A comment',
            'trackNumber' => 2,
            'discNumber' => 1,
            'year' => 2024,
        ];
        $preview = $editing->preview($track, $values);
        $edit = MetadataEditJob::create([
            'track_id' => $track->id,
            'media_file_id' => $track->media_file_id,
            'status' => 'pending',
            'fingerprint' => $preview['fingerprint'],
            'requested_changes' => $values,
            'preview' => $preview,
        ]);

        $this->app->call([new ApplyTrackMetadataEdit($edit->id), 'handle']);

        $this->assertSame('completed', $edit->fresh()->status);
        $this->assertSame('Changed title', $track->fresh()->title);
        $this->assertSame(2, $track->fresh()->track_number);
        $this->assertSame(2024, $track->fresh()->year);
        $this->assertSame('A comment', $track->fresh()->comment);
        $this->assertSame(['Composer'], $track->fresh()->composers);
        $this->assertSame(['Performer'], $track->fresh()->performers);
        $this->assertSame(['Changed artist'], $track->fresh()->artists()->pluck('name')->all());
        $this->assertSame(['verified' => true], $track->fresh()->metadata);
        $this->assertSame('written', file_get_contents($path));
        $this->assertSame(7, $track->fresh()->mediaFile->file_size);
    }

    private function createTrack(): Track
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
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Album',
            'sort_title' => 'Album',
            'relative_path' => 'Artist/Album',
            'relative_path_hash' => hash('sha256', 'artist/album'),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => 'Artist/Album/track.mp3',
            'relative_path_hash' => hash('sha256', 'artist/album/track.mp3'),
            'file_size' => 5,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Track',
            'sort_title' => 'Track',
            'year' => 2000,
            'disc_number' => 1,
            'track_number' => 1,
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        return $track;
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

class FakeTrackMetadataWriter implements TrackMetadataWriter
{
    public function supports(string $path): bool
    {
        return str_ends_with(mb_strtolower($path), '.mp3');
    }

    public function write(string $path, array $values): AudioMetadata
    {
        file_put_contents($path, 'written');

        return new AudioMetadata(
            title: $values['title'],
            artists: $values['artistNames'],
            composers: $values['composers'],
            performers: $values['performers'],
            comment: $values['comment'],
            year: $values['year'],
            trackNumber: $values['trackNumber'],
            discNumber: $values['discNumber'],
            rawMetadata: ['verified' => true],
        );
    }
}
