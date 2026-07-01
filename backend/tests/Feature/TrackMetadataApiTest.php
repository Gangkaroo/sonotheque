<?php

namespace Tests\Feature;

use App\Jobs\ApplyTrackMetadataEdit;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrackMetadataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_previews_and_queues_a_track_metadata_edit(): void
    {
        Queue::fake();
        $track = $this->createTrack('track.mp3');
        $values = [
            'title' => 'Changed title',
            'artistNames' => ['Artist', 'Guest'],
            'composers' => ['Composer'],
            'performers' => ['Performer'],
            'genres' => ['Alternative', 'Rock'],
            'comment' => 'Updated comment',
            'trackNumber' => 2,
            'discNumber' => 1,
            'year' => 2024,
        ];

        $preview = $this->postJson("/api/tracks/{$track->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonPath('supported', true)
            ->assertJsonPath('changes.0.field', 'title')
            ->assertJsonFragment([
                'field' => 'genres',
                'current' => [],
                'proposed' => ['Alternative', 'Rock'],
            ])
            ->json();

        $response = $this->postJson("/api/tracks/{$track->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted()
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('metadata_edit_jobs', [
            'id' => $response->json('id'),
            'track_id' => $track->id,
            'status' => 'pending',
        ]);
        Queue::assertPushed(ApplyTrackMetadataEdit::class);
    }

    public function test_it_rejects_a_stale_preview(): void
    {
        Queue::fake();
        $track = $this->createTrack('track.mp3');
        $values = [
            'title' => 'Changed title',
            'artistNames' => ['Artist'],
            'composers' => [],
            'performers' => [],
            'genres' => [],
            'comment' => null,
            'trackNumber' => 2,
            'discNumber' => 1,
            'year' => 2024,
        ];
        $fingerprint = $this->postJson("/api/tracks/{$track->id}/metadata/preview", $values)->json('fingerprint');
        $track->update(['title' => 'Changed elsewhere']);

        $this->postJson("/api/tracks/{$track->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $fingerprint,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint');
        Queue::assertNothingPushed();
    }

    public function test_it_reports_unsupported_formats_without_queuing_them(): void
    {
        Queue::fake();
        $track = $this->createTrack('track.m4a');
        $values = [
            'title' => 'Changed title',
            'artistNames' => ['Artist'],
            'composers' => [],
            'performers' => [],
            'genres' => [],
            'comment' => null,
            'trackNumber' => 2,
            'discNumber' => 1,
            'year' => 2024,
        ];
        $preview = $this->postJson("/api/tracks/{$track->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonPath('supported', false)
            ->json();

        $this->postJson("/api/tracks/{$track->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('track');
        Queue::assertNothingPushed();
    }

    public function test_it_reports_id3v2_unsynchronization_before_queueing(): void
    {
        Queue::fake();
        $track = $this->createTrack('track.mp3');
        $track->mediaFile->update([
            'raw_metadata' => ['id3v2' => ['majorversion' => 3, 'flags' => ['unsynch' => true]]],
        ]);
        $values = [
            'title' => 'Changed title',
            'artistNames' => ['Artist'],
            'composers' => [],
            'performers' => [],
            'genres' => ['Synthpop'],
            'comment' => null,
            'trackNumber' => 1,
            'discNumber' => 1,
            'year' => 2014,
        ];

        $preview = $this->postJson("/api/tracks/{$track->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonPath('supported', false)
            ->assertJsonPath('supportIssue', 'id3v2_unsynchronization')
            ->json();

        $this->postJson("/api/tracks/{$track->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('track');
        Queue::assertNothingPushed();
    }

    public function test_it_allows_id3v24_unsynchronization(): void
    {
        $track = $this->createTrack('track.mp3');
        $track->mediaFile->update([
            'raw_metadata' => ['id3v2' => ['majorversion' => 4, 'flags' => ['unsynch' => true]]],
        ]);

        $this->postJson("/api/tracks/{$track->id}/metadata/preview", [
            'title' => 'Changed title',
            'artistNames' => ['Artist'],
            'composers' => [],
            'performers' => [],
            'genres' => ['Synthpop'],
            'comment' => null,
            'trackNumber' => 1,
            'discNumber' => 1,
            'year' => 2014,
        ])->assertOk()
            ->assertJsonPath('supported', true)
            ->assertJsonPath('supportIssue', null);
    }

    private function createTrack(string $filename): Track
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
        ]);
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
            'title' => 'Track',
            'sort_title' => 'Track',
            'year' => 2000,
            'disc_number' => 1,
            'track_number' => 1,
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        return $track;
    }
}
