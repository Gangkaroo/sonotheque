<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackPlayStatisticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_counted_play_after_the_threshold(): void
    {
        $track = $this->createTrack(durationMs: 120_000);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 15_000,
            'durationMs' => 120_000,
            'context' => 'track-list',
        ])
            ->assertCreated()
            ->assertJsonPath('counted', true)
            ->assertJsonPath('statistics.playCount', 1);

        $this->assertDatabaseHas('track_play_statistics', [
            'track_id' => $track->id,
            'play_count' => 1,
        ]);
        $this->assertDatabaseHas('track_play_events', [
            'track_id' => $track->id,
            'media_file_id' => $track->media_file_id,
            'listened_ms' => 15_000,
            'duration_ms' => 120_000,
            'counted' => true,
            'source' => 'app',
            'context' => 'track-list',
        ]);
    }

    public function test_it_keeps_short_preview_events_without_incrementing_statistics(): void
    {
        $track = $this->createTrack(durationMs: 120_000);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 5_000,
            'durationMs' => 120_000,
        ])
            ->assertAccepted()
            ->assertJsonPath('counted', false)
            ->assertJsonPath('statistics.playCount', 0);

        $this->assertDatabaseMissing('track_play_statistics', ['track_id' => $track->id]);
        $this->assertDatabaseHas('track_play_events', [
            'track_id' => $track->id,
            'listened_ms' => 5_000,
            'counted' => false,
        ]);
    }

    public function test_it_counts_tracks_shorter_than_the_threshold_immediately(): void
    {
        $track = $this->createTrack(durationMs: 10_000);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 0,
            'durationMs' => 10_000,
        ])
            ->assertCreated()
            ->assertJsonPath('counted', true)
            ->assertJsonPath('statistics.playCount', 1);
    }

    public function test_track_detail_includes_play_statistics(): void
    {
        $track = $this->createTrack(durationMs: 120_000);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 15_000,
            'durationMs' => 120_000,
        ])->assertCreated();

        $this->getJson("/api/catalog/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('playStatistics.playCount', 1)
            ->assertJsonStructure([
                'playStatistics' => ['playCount', 'firstPlayedAt', 'lastPlayedAt'],
            ]);
    }

    private function createTrack(int $durationMs): Track
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
            'relative_path' => 'Artist/Album/track.mp3',
            'relative_path_hash' => hash('sha256', 'artist/album/track.mp3'),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Track',
            'sort_title' => 'Track',
            'duration_ms' => $durationMs,
            'disc_number' => 1,
            'track_number' => 1,
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        return $track;
    }
}
