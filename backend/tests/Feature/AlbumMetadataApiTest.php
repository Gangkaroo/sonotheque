<?php

namespace Tests\Feature;

use App\Jobs\ApplyAlbumMetadataEdit;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\MetadataEditItem;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlbumMetadataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_previews_every_file_and_queues_an_album_batch(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $values = $this->values();

        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonPath('supportedFiles', 2)
            ->assertJsonCount(2, 'files')
            ->assertJsonPath('changes.0.field', 'albumTitle')
            ->json();

        $response = $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted()
            ->assertJsonPath('type', 'album')
            ->assertJsonPath('totalItems', 2);

        $this->assertDatabaseCount('metadata_edit_items', 2);
        $this->assertDatabaseHas('metadata_edit_jobs', [
            'id' => $response->json('id'),
            'album_id' => $album->id,
            'status' => 'pending',
        ]);
        Queue::assertPushed(ApplyAlbumMetadataEdit::class);
    }

    public function test_it_rejects_mixed_format_batches_before_writing(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.m4a']);
        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $this->values())
            ->assertOk()
            ->assertJsonPath('unsupportedFiles', 1)
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$this->values(),
            'fingerprint' => $preview['fingerprint'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('album');
        Queue::assertNothingPushed();
    }

    public function test_it_reports_id3v2_unsynchronization_before_queueing_a_batch(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $album->tracks->first()->mediaFile->update([
            'raw_metadata' => ['id3v2' => ['majorversion' => 3, 'flags' => ['unsynch' => true]]],
        ]);

        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $this->values())
            ->assertOk()
            ->assertJsonPath('unsupportedFiles', 1)
            ->assertJsonPath('files.0.supportIssue', 'id3v2_unsynchronization')
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$this->values(),
            'fingerprint' => $preview['fingerprint'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('album');
        Queue::assertNothingPushed();
    }

    public function test_it_only_writes_shared_fields_that_actually_changed(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $values = [
            'albumTitle' => 'Changed album',
            'albumArtist' => 'Artist',
            'releaseYear' => 2000,
            'totalDiscs' => null,
            'genres' => [],
        ];
        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonCount(1, 'changes')
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted();

        $this->assertSame(['albumTitle' => 'Changed album'], MetadataEditItem::firstOrFail()->requested_changes);
    }

    /** @return array{albumTitle: string, albumArtist: string, releaseYear: int, totalDiscs: int, genres: list<string>} */
    private function values(): array
    {
        return [
            'albumTitle' => 'Changed album',
            'albumArtist' => 'Changed artist',
            'releaseYear' => 2025,
            'totalDiscs' => 2,
            'genres' => ['Doom', 'Metal'],
        ];
    }

    /** @param list<string> $filenames */
    private function createAlbum(array $filenames): Album
    {
        $artist = Artist::create([
            'name' => 'Artist',
            'sort_name' => 'Artist',
            'browse_initial' => 'A',
        ]);
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/Music',
            'path_hash' => hash('sha256', 'd:/music'),
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
        foreach ($filenames as $position => $filename) {
            $mediaFile = MediaFile::create([
                'library_root_id' => $root->id,
                'album_id' => $album->id,
                'relative_path' => "Artist/Album/{$filename}",
                'relative_path_hash' => hash('sha256', "artist/album/{$filename}"),
                'file_size' => 1,
                'modified_at' => now(),
                'last_seen_at' => now(),
            ]);
            $track = Track::create([
                'album_id' => $album->id,
                'media_file_id' => $mediaFile->id,
                'title' => 'Track '.($position + 1),
                'sort_title' => 'Track '.($position + 1),
                'disc_number' => 1,
                'track_number' => $position + 1,
            ]);
            $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);
        }

        return $album;
    }
}
