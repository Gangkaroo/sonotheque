<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaybackStatisticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_recent_counted_plays(): void
    {
        $firstTrack = $this->createTrack('First track');
        $secondTrack = $this->createTrack('Second track');

        $this->recordPlay($firstTrack, '2026-06-26T10:00:00Z', 'recent-first');
        $this->recordPlay($secondTrack, '2026-06-26T11:00:00Z', 'recent-second');

        $this->getJson('/api/statistics/recent-plays')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('items.0.track.title', 'Second track')
            ->assertJsonPath('items.0.playedAt', '2026-06-26T11:00:00.000000Z')
            ->assertJsonPath('items.1.track.title', 'First track');
    }

    public function test_it_lists_recent_plays_for_one_track(): void
    {
        $firstTrack = $this->createTrack('First track');
        $secondTrack = $this->createTrack('Second track');

        $this->recordPlay($firstTrack, '2026-06-26T10:00:00Z', 'track-recent-first-1');
        $this->recordPlay($secondTrack, '2026-06-26T11:00:00Z', 'track-recent-second-1');
        $this->recordPlay($firstTrack, '2026-06-26T12:00:00Z', 'track-recent-first-2');

        $this->getJson("/api/statistics/tracks/{$firstTrack->id}/recent-plays")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('items.0.track.title', 'First track')
            ->assertJsonPath('items.0.playedAt', '2026-06-26T12:00:00.000000Z')
            ->assertJsonPath('items.1.track.title', 'First track');
    }


    public function test_it_lists_most_played_tracks(): void
    {
        $firstTrack = $this->createTrack('First track');
        $secondTrack = $this->createTrack('Second track');

        $this->recordPlay($firstTrack, '2026-06-26T10:00:00Z', 'most-first-1');
        $this->recordPlay($secondTrack, '2026-06-26T11:00:00Z', 'most-second-1');
        $this->recordPlay($secondTrack, '2026-06-26T12:00:00Z', 'most-second-2');

        $this->getJson('/api/statistics/most-played-tracks')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('items.0.title', 'Second track')
            ->assertJsonPath('items.0.playStatistics.playCount', 2)
            ->assertJsonPath('items.1.title', 'First track')
            ->assertJsonPath('items.1.playStatistics.playCount', 1);
    }

    public function test_it_lists_most_played_albums_by_summed_track_play_count(): void
    {
        $firstAlbumTrack = $this->createTrack('First track', 'First album');
        $secondAlbumTrack = $this->createTrack('Second track', 'Second album');

        $this->recordPlay($firstAlbumTrack, '2026-06-26T10:00:00Z', 'album-first-1');
        $this->recordPlay($secondAlbumTrack, '2026-06-26T11:00:00Z', 'album-second-1');
        $this->recordPlay($secondAlbumTrack, '2026-06-26T12:00:00Z', 'album-second-2');

        $this->getJson('/api/statistics/most-played-albums')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('items.0.title', 'Second album')
            ->assertJsonPath('items.0.playCount', 2)
            ->assertJsonPath('items.1.title', 'First album')
            ->assertJsonPath('items.1.playCount', 1);
    }

    private function recordPlay(Track $track, string $playedAt, string $sessionKey): void
    {
        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 60_000,
            'durationMs' => 120_000,
            'playedAt' => $playedAt,
            'sessionKey' => $sessionKey,
        ])->assertCreated();
    }

    private function createTrack(string $title, string $albumTitle = 'Album'): Track
    {
        $artist = Artist::firstOrCreate([
            'name' => 'Artist',
        ], [
            'sort_name' => 'Artist',
            'browse_initial' => 'A',
        ]);
        $root = Library::firstOrCreate(['name' => 'Test'])->roots()->firstOrCreate([
            'path_hash' => hash('sha256', 'd:/music'),
        ], [
            'name' => 'Music',
            'path' => 'D:/Music',
        ]);
        $album = Album::firstOrCreate([
            'library_root_id' => $root->id,
            'relative_path_hash' => hash('sha256', 'artist/'.$albumTitle),
        ], [
            'primary_artist_id' => $artist->id,
            'title' => $albumTitle,
            'sort_title' => $albumTitle,
            'relative_path' => 'Artist/'.$albumTitle,
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => "Artist/{$albumTitle}/{$title}.mp3",
            'relative_path_hash' => hash('sha256', "artist/{$albumTitle}/{$title}.mp3"),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $title,
            'sort_title' => $title,
            'duration_ms' => 120_000,
            'disc_number' => 1,
            'track_number' => 1,
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        return $track;
    }
}
