<?php

namespace Tests\Feature;

use App\Jobs\ApplyAlbumMetadataEdit;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\MetadataBackup;
use App\Models\Track;
use App\Music\Metadata\AlbumMetadataEditing;
use App\Music\Metadata\TrackMetadataWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FailingAlbumTrackMetadataWriter;
use Tests\Fakes\FakeAlbumTrackMetadataWriter;
use Tests\TestCase;

class ApplyAlbumMetadataEditJobTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    private string $backupPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->musicPath = storage_path('framework/testing/album-metadata-edit-'.bin2hex(random_bytes(6)));
        $this->backupPath = $this->musicPath.'-backups';
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album', 0777, true);
        mkdir($this->backupPath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->musicPath);
        $this->deleteDirectory($this->backupPath);
        parent::tearDown();
    }

    public function test_the_batch_updates_files_progress_and_album_only_after_every_file_succeeds(): void
    {
        Queue::fake();
        $this->app->instance(TrackMetadataWriter::class, new FakeAlbumTrackMetadataWriter());
        $album = $this->createAlbum();
        $albumOnlyArtist = Artist::create([
            'name' => 'Album-only artist',
            'sort_name' => 'Album-only artist',
            'browse_initial' => 'A',
        ]);
        $album->update(['primary_artist_id' => $albumOnlyArtist->id]);
        ApplicationSetting::current()->update([
            'metadata_backups_enabled' => true,
            'metadata_backup_path' => $this->backupPath,
            'metadata_backup_retention_days' => 30,
        ]);
        $editing = $this->app->make(AlbumMetadataEditing::class);
        $values = [
            'albumTitle' => 'Changed album',
            'albumArtist' => 'Changed artist',
            'releaseYear' => 2025,
            'totalDiscs' => 2,
            'genres' => ['Doom', 'Metal'],
            'comment' => null,
        ];
        $preview = $editing->preview($album, $values);
        $edit = $editing->queue($album, $values, $preview['fingerprint']);

        $this->app->call([new ApplyAlbumMetadataEdit($edit->id), 'handle']);

        $edit->refresh();
        $album->refresh();
        $this->assertSame('completed', $edit->status);
        $this->assertSame(2, $edit->processed_items);
        $this->assertSame(2, $edit->succeeded_items);
        $this->assertSame(0, $edit->failed_items);
        $this->assertSame('Changed album', $album->title);
        $this->assertSame('Changed artist', $album->primaryArtist->name);
        $this->assertDatabaseMissing(Artist::class, ['name' => 'Album-only artist']);
        $this->assertSame(2025, $album->original_release_year);
        $this->assertSame(2, $album->disc_total);
        $this->assertEqualsCanonicalizing(['Doom', 'Metal'], $album->tracks->first()->genres()->pluck('name')->all());
        $this->assertSame([null, null], $album->tracks()->orderBy('track_number')->pluck('comment')->all());
        $this->assertDatabaseMissing(Genre::class, ['name' => 'Old genre']);
        $this->assertSame(['Track 1', 'Track 2'], $album->tracks()->orderBy('track_number')->pluck('title')->all());
        $this->assertSame([1, 2], $album->tracks()->orderBy('track_number')->pluck('track_number')->all());
        $this->assertSame(2, MetadataBackup::count());
        $this->assertSame(['audio', 'audio'], MetadataBackup::all()->map(
            fn (MetadataBackup $backup) => file_get_contents($backup->backup_root.DIRECTORY_SEPARATOR.$backup->backup_relative_path),
        )->all());
    }

    public function test_a_partial_failure_keeps_the_shared_album_catalog_unchanged(): void
    {
        Queue::fake();
        $this->app->instance(TrackMetadataWriter::class, new FailingAlbumTrackMetadataWriter());
        $album = $this->createAlbum();
        $editing = $this->app->make(AlbumMetadataEditing::class);
        $values = [
            'albumTitle' => 'Changed album',
            'albumArtist' => 'Changed artist',
            'releaseYear' => 2025,
            'totalDiscs' => 2,
            'genres' => ['Doom'],
        ];
        $preview = $editing->preview($album, $values);
        $edit = $editing->queue($album, $values, $preview['fingerprint']);

        $this->app->call([new ApplyAlbumMetadataEdit($edit->id), 'handle']);

        $edit->refresh();
        $album->refresh();
        $this->assertSame('partial', $edit->status);
        $this->assertSame(2, $edit->processed_items);
        $this->assertSame(1, $edit->succeeded_items);
        $this->assertSame(1, $edit->failed_items);
        $this->assertSame('Album', $album->title);
        $this->assertSame('Artist', $album->primaryArtist->name);
        $this->assertDatabaseHas('metadata_edit_items', [
            'metadata_edit_job_id' => $edit->id,
            'status' => 'failed',
            'error' => 'Simulated write failure.',
        ]);
    }

    private function createAlbum(): Album
    {
        $artist = Artist::create([
            'name' => 'Artist',
            'sort_name' => 'Artist',
            'browse_initial' => 'A',
        ]);
        $oldGenre = Genre::create(['name' => 'Old genre']);
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
            'original_release_year' => 2000,
        ]);
        foreach ([1, 2] as $position) {
            $relativePath = "Artist/Album/0{$position}.mp3";
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
                'disc_number' => 1,
                'track_number' => $position,
                'comment' => 'Remove me',
            ]);
            $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);
            $track->genres()->attach($oldGenre);
        }

        return $album;
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
