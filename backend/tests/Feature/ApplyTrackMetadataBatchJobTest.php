<?php

namespace Tests\Feature;

use App\Jobs\ApplyTrackMetadataBatch;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use App\Music\Metadata\TrackBatchMetadataEditing;
use App\Music\Metadata\TrackMetadataWriter;
use App\Music\Scanning\AudioMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApplyTrackMetadataBatchJobTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->musicPath = storage_path('framework/testing/track-batch-'.bin2hex(random_bytes(6)));
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->musicPath);
        parent::tearDown();
    }

    public function test_it_updates_each_selected_track_and_completes_the_parent_job(): void
    {
        Queue::fake();
        $this->app->instance(TrackMetadataWriter::class, new class () implements TrackMetadataWriter {
            public function supports(string $path): bool
            {
                return true;
            }

            public function write(string $path, array $values): AudioMetadata
            {
                file_put_contents($path, 'written');

                return new AudioMetadata(
                    title: pathinfo($path, PATHINFO_FILENAME),
                    artists: $values['artistNames'],
                    composers: $values['composers'],
                    performers: $values['performers'],
                    genres: $values['genres'],
                    comment: $values['comment'],
                    year: $values['year'],
                    trackNumber: $values['trackNumber'],
                    discNumber: $values['discNumber'],
                    rawMetadata: ['verified' => true],
                );
            }
        });
        [$album, $tracks] = $this->createAlbum();
        $changes = [
            'artistNames' => ['Changed artist'],
            'composers' => ['Composer'],
            'performers' => ['Performer'],
            'genres' => ['Progressive Rock'],
            'comment' => null,
            'trackNumber' => 4,
            'discNumber' => 2,
            'year' => 2025,
        ];
        $editing = $this->app->make(TrackBatchMetadataEditing::class);
        $preview = $editing->preview($album, $tracks, $changes);
        $edit = $editing->queue($album, $tracks, $changes, $preview['fingerprint']);

        $this->app->call([new ApplyTrackMetadataBatch($edit->id), 'handle']);

        $edit->refresh();
        $this->assertSame('completed', $edit->status);
        $this->assertSame(2, $edit->processed_items);
        $this->assertSame(2, $edit->succeeded_items);
        $this->assertSame(0, $edit->failed_items);
        foreach ($tracks as $track) {
            $track->refresh();
            $this->assertSame(2, $track->disc_number);
            $this->assertSame(4, $track->track_number);
            $this->assertSame(2025, $track->year);
            $this->assertNull($track->comment);
            $this->assertSame(['Changed artist'], $track->artists()->pluck('name')->all());
            $this->assertSame(['Progressive Rock'], $track->genres()->pluck('name')->all());
            $this->assertSame('written', file_get_contents($this->absolutePath($track)));
        }
    }

    /** @return array{Album, \Illuminate\Database\Eloquent\Collection<int, Track>} */
    private function createAlbum(): array
    {
        $artist = Artist::create(['name' => 'Artist', 'sort_name' => 'Artist', 'browse_initial' => 'A']);
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
        foreach ([1, 2] as $position) {
            $relativePath = "Artist/Album/track-{$position}.mp3";
            file_put_contents($this->musicPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath), 'audio');
            $mediaFile = MediaFile::create([
                'library_root_id' => $root->id,
                'album_id' => $album->id,
                'relative_path' => $relativePath,
                'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
                'file_size' => 5,
                'modified_at' => now(),
                'last_seen_at' => now(),
            ]);
            $track = Track::create([
                'album_id' => $album->id,
                'media_file_id' => $mediaFile->id,
                'title' => "Track {$position}",
                'sort_title' => "Track {$position}",
                'track_number' => $position,
                'disc_number' => 1,
                'year' => 2000,
                'comment' => 'Remove me',
            ]);
            $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);
        }

        return [$album, $album->tracks()->orderBy('id')->get()];
    }

    private function absolutePath(Track $track): string
    {
        return $this->musicPath.DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $track->mediaFile->relative_path,
        );
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
