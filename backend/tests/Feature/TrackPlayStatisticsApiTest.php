<?php

namespace Tests\Feature;

use App\Jobs\SynchronizeTrackPlaybackStatistics;
use App\Jobs\ScrobbleTrackPlayEvent;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use App\Models\TrackPlayEvent;
use App\Music\LastFm\LastFmApiClient;
use App\Music\PlaybackStatistics\PlaybackStatisticsFileSynchronizer;
use App\Music\Streaming\TrackStreamActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrackPlayStatisticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_counted_play_after_the_threshold(): void
    {
        $track = $this->createTrack(durationMs: 120_000);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 60_000,
            'durationMs' => 120_000,
            'context' => 'track-list',
            'sessionKey' => 'play-session-1',
        ])
            ->assertCreated()
            ->assertJsonPath('counted', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('statistics.playCount', 1);

        $this->assertDatabaseHas('track_play_statistics', [
            'track_id' => $track->id,
            'play_count' => 1,
        ]);
        $this->assertDatabaseHas('track_play_events', [
            'track_id' => $track->id,
            'media_file_id' => $track->media_file_id,
            'listened_ms' => 60_000,
            'duration_ms' => 120_000,
            'counted' => true,
            'source' => 'app',
            'context' => 'track-list',
            'session_key' => 'play-session-1',
        ]);
    }

    public function test_it_accepts_a_small_duration_change_after_metadata_was_rewritten(): void
    {
        $track = $this->createTrack(durationMs: 230_478);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 115_221,
            'durationMs' => 230_442,
            'sessionKey' => 'metadata-rewrite-session',
        ])
            ->assertCreated()
            ->assertJsonPath('counted', true)
            ->assertJsonPath('statistics.playCount', 1);

        $this->assertDatabaseHas('track_play_events', [
            'track_id' => $track->id,
            'listened_ms' => 115_221,
            'duration_ms' => 230_442,
            'counted' => true,
        ]);
    }

    public function test_it_does_not_count_the_same_playback_session_twice(): void
    {
        $track = $this->createTrack(durationMs: 120_000);

        $payload = [
            'listenedMs' => 60_000,
            'durationMs' => 120_000,
            'sessionKey' => 'same-playback-session',
        ];

        $this->postJson("/api/tracks/{$track->id}/plays", $payload)
            ->assertCreated()
            ->assertJsonPath('counted', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('statistics.playCount', 1);

        $this->postJson("/api/tracks/{$track->id}/plays", $payload)
            ->assertOk()
            ->assertJsonPath('counted', true)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('statistics.playCount', 1);

        $this->assertDatabaseHas('track_play_statistics', [
            'track_id' => $track->id,
            'play_count' => 1,
        ]);
        $this->assertDatabaseCount('track_play_events', 1);
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

    public function test_it_rejects_impossible_listening_durations(): void
    {
        Queue::fake();
        ApplicationSetting::current()->update([
            'lastfm_scrobbling_enabled' => true,
            'lastfm_api_key' => str_repeat('a', 32),
            'lastfm_api_secret' => str_repeat('b', 32),
            'lastfm_session_key' => 'session-key',
            'lastfm_username' => 'listener',
        ]);
        $track = $this->createTrack(durationMs: 120_000);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 60_000,
            'durationMs' => 120_000,
            'playedAt' => now()->subSeconds(2)->toIso8601String(),
            'sessionKey' => 'corrupted-session',
        ])
            ->assertAccepted()
            ->assertJsonPath('counted', false)
            ->assertJsonPath('lastFmQueued', false)
            ->assertJsonPath('statistics.playCount', 0);

        $this->assertDatabaseMissing('track_play_statistics', ['track_id' => $track->id]);
        Queue::assertNotPushed(ScrobbleTrackPlayEvent::class);
    }

    public function test_it_does_not_count_tracks_that_are_thirty_seconds_or_shorter(): void
    {
        $track = $this->createTrack(durationMs: 10_000);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 0,
            'durationMs' => 10_000,
        ])
            ->assertAccepted()
            ->assertJsonPath('counted', false)
            ->assertJsonPath('statistics.playCount', 0);
    }

    public function test_it_queues_file_synchronization_for_a_counted_play_when_enabled(): void
    {
        Queue::fake();
        $this->freezeTime();
        config(['sonotheque.play_statistics_sync_delay_seconds' => 30]);
        ApplicationSetting::current()->update([
            'import_play_statistics_from_tags' => true,
            'export_play_statistics_to_tags' => true,
        ]);
        $track = $this->createTrack(durationMs: 120_000);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 60_000,
            'durationMs' => 120_000,
            'sessionKey' => 'synchronized-play',
        ])->assertCreated();

        $expectedDelay = now()->addSeconds(90);
        Queue::assertPushed(
            SynchronizeTrackPlaybackStatistics::class,
            fn ($job): bool => $job->trackId === $track->id
                && $job->delay?->equalTo($expectedDelay) === true,
        );

        Queue::fake();
        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 60_000,
            'durationMs' => 120_000,
            'sessionKey' => 'synchronized-play',
        ])->assertOk()->assertJsonPath('duplicate', true);
        Queue::assertNothingPushed();
    }

    public function test_file_synchronization_is_deferred_while_the_track_is_being_streamed(): void
    {
        Queue::fake();
        $this->freezeTime();
        config([
            'cache.default' => 'array',
            'sonotheque.audio_stream_activity_grace_seconds' => 300,
        ]);
        ApplicationSetting::current()->update([
            'import_play_statistics_from_tags' => true,
            'export_play_statistics_to_tags' => true,
        ]);
        $track = $this->createTrack(durationMs: 120_000);
        $activity = app(TrackStreamActivity::class);
        $activity->touch($track->id);
        $synchronizer = $this->mock(PlaybackStatisticsFileSynchronizer::class);
        $synchronizer->shouldNotReceive('synchronize');

        (new SynchronizeTrackPlaybackStatistics($track->id))->handle($synchronizer, $activity);

        Queue::assertPushed(
            SynchronizeTrackPlaybackStatistics::class,
            fn ($job): bool => $job->trackId === $track->id
                && $job->delay?->equalTo(now()->addSeconds(301)) === true,
        );
    }

    public function test_it_does_not_queue_file_synchronization_when_disabled(): void
    {
        Queue::fake();
        $track = $this->createTrack(durationMs: 120_000);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 60_000,
            'durationMs' => 120_000,
        ])->assertCreated();

        Queue::assertNothingPushed();
    }

    public function test_track_detail_includes_play_statistics(): void
    {
        $track = $this->createTrack(durationMs: 120_000);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 60_000,
            'durationMs' => 120_000,
        ])->assertCreated();

        $this->getJson("/api/catalog/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('playStatistics.playCount', 1)
            ->assertJsonStructure([
                'playStatistics' => ['playCount', 'firstPlayedAt', 'lastPlayedAt'],
            ]);
    }

    public function test_it_queues_a_lastfm_scrobble_for_an_eligible_play_when_connected(): void
    {
        Queue::fake();
        ApplicationSetting::current()->update([
            'lastfm_scrobbling_enabled' => true,
            'lastfm_api_key' => str_repeat('a', 32),
            'lastfm_api_secret' => str_repeat('b', 32),
            'lastfm_session_key' => 'session-key',
            'lastfm_username' => 'listener',
        ]);
        $track = $this->createTrack(durationMs: 600_000);

        $this->postJson("/api/tracks/{$track->id}/plays", [
            'listenedMs' => 240_000,
            'durationMs' => 600_000,
            'sessionKey' => 'lastfm-play',
        ])->assertCreated()
            ->assertJsonPath('lastFmQueued', true);

        Queue::assertPushed(ScrobbleTrackPlayEvent::class);
        $this->assertDatabaseHas('track_play_events', [
            'track_id' => $track->id,
            'lastfm_status' => 'pending',
        ]);

        Http::fake(['*' => Http::response([
            'scrobbles' => [
                '@attr' => ['accepted' => '1', 'ignored' => '0'],
                'scrobble' => ['ignoredMessage' => ['code' => '0', '#text' => '']],
            ],
        ])]);
        $event = TrackPlayEvent::query()->where('track_id', $track->id)->sole();
        (new ScrobbleTrackPlayEvent($event->id))->handle(app(LastFmApiClient::class));

        $this->assertSame('sent', $event->refresh()->lastfm_status);
        Http::assertSent(fn ($request): bool => $request['artist'] === 'Artist'
            && $request['track'] === 'Track'
            && $request['album'] === 'Album');
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
