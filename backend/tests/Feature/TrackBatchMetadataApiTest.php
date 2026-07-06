<?php

namespace Tests\Feature;

use App\Jobs\ApplyTrackMetadataBatch;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\MetadataEditItem;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrackBatchMetadataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_mixed_values_and_queues_only_changed_values_per_track(): void
    {
        Queue::fake();
        [$album, $tracks] = $this->createAlbum();
        $trackIds = $tracks->pluck('id')->all();

        $this->postJson("/api/albums/{$album->id}/tracks/metadata/preview", [
            'trackIds' => $trackIds,
            'changes' => [],
        ])->assertOk()
            ->assertJsonPath('fields.discNumber.mixed', true)
            ->assertJsonPath('fields.discNumber.values', [1, 2])
            ->assertJsonPath('fields.comment.mixed', true)
            ->assertJsonPath('affectedFiles', 0);

        $changes = ['comment' => null, 'discNumber' => 2];
        $preview = $this->postJson("/api/albums/{$album->id}/tracks/metadata/preview", [
            'trackIds' => $trackIds,
            'changes' => $changes,
        ])->assertOk()
            ->assertJsonPath('affectedFiles', 2)
            ->assertJsonPath('unsupportedFiles', 0)
            ->json();

        $response = $this->postJson("/api/albums/{$album->id}/tracks/metadata-edits", [
            'trackIds' => $trackIds,
            'changes' => $changes,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted()
            ->assertJsonPath('type', 'track_batch')
            ->assertJsonPath('totalItems', 2);

        $this->assertDatabaseHas('metadata_edit_jobs', [
            'id' => $response->json('id'),
            'album_id' => $album->id,
            'type' => 'track_batch',
            'status' => 'pending',
        ]);
        $items = MetadataEditItem::query()->orderBy('track_id')->get();
        $this->assertSame(['comment' => null, 'discNumber' => 2], $items[0]->requested_changes);
        $this->assertSame(['comment' => null], $items[1]->requested_changes);
        Queue::assertPushed(ApplyTrackMetadataBatch::class);
    }

    public function test_it_rejects_tracks_outside_the_album_and_unsupported_affected_files(): void
    {
        Queue::fake();
        [$album, $tracks] = $this->createAlbum();
        [$otherAlbum, $otherTracks] = $this->createAlbum('Other album');

        $this->postJson("/api/albums/{$album->id}/tracks/metadata/preview", [
            'trackIds' => [$otherTracks->first()->id],
            'changes' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('trackIds.0');

        $tracks->first()->mediaFile->update([
            'raw_metadata' => ['id3v2' => ['majorversion' => 3, 'flags' => ['unsynch' => true]]],
        ]);
        $payload = [
            'trackIds' => $tracks->pluck('id')->all(),
            'changes' => ['comment' => null],
        ];
        $preview = $this->postJson("/api/albums/{$album->id}/tracks/metadata/preview", $payload)
            ->assertOk()
            ->assertJsonPath('unsupportedFiles', 1)
            ->json();

        $this->postJson("/api/albums/{$album->id}/tracks/metadata-edits", [
            ...$payload,
            'fingerprint' => $preview['fingerprint'],
        ])->assertUnprocessable()->assertJsonValidationErrors('tracks');
        Queue::assertNothingPushed();
        $this->assertNotSame($album->id, $otherAlbum->id);
    }

    /** @return array{Album, \Illuminate\Database\Eloquent\Collection<int, Track>} */
    private function createAlbum(string $title = 'Album'): array
    {
        $artist = Artist::create([
            'name' => $title.' artist',
            'sort_name' => $title.' artist',
            'browse_initial' => 'A',
        ]);
        $root = Library::create(['name' => $title])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/'.$title,
            'path_hash' => hash('sha256', 'd:/'.mb_strtolower($title)),
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => $title,
            'sort_title' => $title,
            'relative_path' => $title,
            'relative_path_hash' => hash('sha256', mb_strtolower($title)),
        ]);
        $genres = [Genre::firstOrCreate(['name' => 'Rock']), Genre::firstOrCreate(['name' => 'Metal'])];
        foreach ([1, 2] as $position) {
            $mediaFile = MediaFile::create([
                'library_root_id' => $root->id,
                'album_id' => $album->id,
                'relative_path' => "{$title}/0{$position}.mp3",
                'relative_path_hash' => hash('sha256', mb_strtolower("{$title}/0{$position}.mp3")),
                'file_size' => 1,
                'modified_at' => now(),
                'last_seen_at' => now(),
            ]);
            $track = Track::create([
                'album_id' => $album->id,
                'media_file_id' => $mediaFile->id,
                'title' => "Track {$position}",
                'sort_title' => "Track {$position}",
                'track_number' => $position,
                'disc_number' => $position,
                'comment' => $position === 1 ? 'Remove me' : 'Another comment',
            ]);
            $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);
            $track->genres()->attach($genres[$position - 1]);
        }

        return [$album, $album->tracks()->orderBy('id')->get()];
    }
}
