<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use App\Models\TrackPlayEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LastFmSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_connects_and_completes_lastfm_authorization_without_exposing_secrets(): void
    {
        Http::fakeSequence()
            ->push(['token' => 'authorization-token'])
            ->push(['session' => ['name' => 'listener', 'key' => 'session-key']]);

        $connection = $this->postJson('/api/settings/lastfm/connect', [
            'apiKey' => str_repeat('a', 32),
            'apiSecret' => str_repeat('b', 32),
        ])->assertOk()
            ->assertJsonPath('authorizationPending', true)
            ->assertJsonPath('connected', false)
            ->assertJsonMissingPath('apiSecret')
            ->assertJsonMissingPath('sessionKey')
            ->json();

        $this->assertStringContainsString('authorization-token', $connection['authorizationUrl']);

        $this->postJson('/api/settings/lastfm/complete')
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('username', 'listener')
            ->assertJsonMissingPath('sessionKey');

        $settings = ApplicationSetting::current();
        $this->assertSame(str_repeat('b', 32), $settings->lastfm_api_secret);
        $this->assertSame('session-key', $settings->lastfm_session_key);
        $this->assertDatabaseMissing('application_settings', [
            'lastfm_api_secret' => str_repeat('b', 32),
        ]);
    }

    public function test_it_can_disable_and_disconnect_a_connected_account(): void
    {
        $settings = ApplicationSetting::current();
        $settings->update([
            'lastfm_scrobbling_enabled' => true,
            'lastfm_api_key' => str_repeat('a', 32),
            'lastfm_api_secret' => str_repeat('b', 32),
            'lastfm_session_key' => 'session-key',
            'lastfm_username' => 'listener',
        ]);

        $this->patchJson('/api/settings/lastfm', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('enabled', false);

        $this->deleteJson('/api/settings/lastfm')
            ->assertOk()
            ->assertJsonPath('connected', false);

        $this->assertNull($settings->refresh()->lastfm_session_key);
    }

    public function test_it_lists_and_filters_lastfm_deliveries_with_status_counts(): void
    {
        $track = $this->createTrack();
        TrackPlayEvent::create([
            'track_id' => $track->id,
            'media_file_id' => $track->media_file_id,
            'played_at' => now()->subMinute(),
            'listened_ms' => 120_000,
            'duration_ms' => 180_000,
            'counted' => true,
            'source' => 'app',
            'lastfm_status' => 'sent',
            'lastfm_attempts' => 1,
            'lastfm_scrobbled_at' => now(),
        ]);
        TrackPlayEvent::create([
            'track_id' => $track->id,
            'media_file_id' => $track->media_file_id,
            'played_at' => now(),
            'listened_ms' => 120_000,
            'duration_ms' => 180_000,
            'counted' => true,
            'source' => 'app',
            'lastfm_status' => 'failed',
            'lastfm_attempts' => 5,
            'lastfm_error' => 'Last.fm rejected the request.',
        ]);

        $this->getJson('/api/settings/lastfm/deliveries?status=failed')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.status', 'failed')
            ->assertJsonPath('items.0.attempts', 5)
            ->assertJsonPath('items.0.error', 'Last.fm rejected the request.')
            ->assertJsonPath('items.0.track.title', 'Track')
            ->assertJsonPath('items.0.track.album.title', 'Album')
            ->assertJsonPath('summary.pending', 0)
            ->assertJsonPath('summary.sent', 1)
            ->assertJsonPath('summary.ignored', 0)
            ->assertJsonPath('summary.failed', 1);

        $this->getJson('/api/settings/lastfm/deliveries?status=unknown')
            ->assertUnprocessable();
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
            'duration_ms' => 180_000,
            'disc_number' => 1,
            'track_number' => 1,
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        return $track;
    }
}
