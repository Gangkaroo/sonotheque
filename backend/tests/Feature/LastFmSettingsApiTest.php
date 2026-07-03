<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
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
}
