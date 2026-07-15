<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscogsSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_connects_a_discogs_account_without_exposing_the_token(): void
    {
        Http::fake(['api.discogs.com/*' => Http::response([
            'id' => 12345,
            'username' => 'collector',
            'resource_url' => 'https://api.discogs.com/users/collector',
        ])]);

        $this->postJson('/api/settings/discogs/connect', [
            'personalAccessToken' => 'discogs-personal-token',
        ])
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('username', 'collector')
            ->assertJsonPath('userId', 12345)
            ->assertJsonMissingPath('personalAccessToken');

        $settings = ApplicationSetting::current();
        $this->assertSame('discogs-personal-token', $settings->discogs_personal_access_token);
        $this->assertDatabaseMissing('application_settings', [
            'discogs_personal_access_token' => 'discogs-personal-token',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.discogs.com/oauth/identity'
            && $request->hasHeader('Authorization', 'Discogs token=discogs-personal-token')
            && $request->hasHeader('User-Agent'));
    }

    public function test_it_rejects_an_invalid_discogs_token_and_can_disconnect(): void
    {
        Http::fake(['api.discogs.com/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->postJson('/api/settings/discogs/connect', [
            'personalAccessToken' => 'invalid-token',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Discogs rejected the personal access token.');

        $settings = ApplicationSetting::current();
        $settings->update([
            'discogs_personal_access_token' => 'valid-token',
            'discogs_username' => 'collector',
            'discogs_user_id' => 12345,
            'discogs_connected_at' => now(),
        ]);

        $this->deleteJson('/api/settings/discogs')
            ->assertOk()
            ->assertJsonPath('connected', false)
            ->assertJsonPath('username', null);

        $this->assertNull($settings->refresh()->discogs_personal_access_token);
    }
}
