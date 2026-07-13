<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Music\Enrichment\Data\LyricsLookup;
use App\Music\Enrichment\OnlineContentCacheRepository;
use App\Enums\OnlineContentStatus;
use App\Enums\OnlineContentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnlineEnrichmentSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_online_enrichment_is_disabled_by_default_and_can_be_enabled(): void
    {
        $this->getJson('/api/settings/online-enrichment')
            ->assertOk()
            ->assertExactJson([
                'informationEnabled' => false,
                'lyricsEnabled' => false,
                'cache' => [
                    'total' => 0,
                    'ready' => 0,
                    'notFound' => 0,
                    'errors' => 0,
                    'stale' => 0,
                ],
            ]);

        $this->patchJson('/api/settings/online-enrichment', [
            'informationEnabled' => true,
            'lyricsEnabled' => true,
        ])->assertOk()
            ->assertJsonPath('informationEnabled', true)
            ->assertJsonPath('lyricsEnabled', true);

        $settings = ApplicationSetting::current();
        $this->assertTrue($settings->online_information_enabled);
        $this->assertTrue($settings->online_lyrics_enabled);
    }

    public function test_online_enrichment_settings_require_booleans(): void
    {
        $this->patchJson('/api/settings/online-enrichment', [
            'informationEnabled' => 'yes',
            'lyricsEnabled' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['informationEnabled', 'lyricsEnabled']);
    }

    public function test_cache_can_be_cleared_without_flushing_other_application_caches(): void
    {
        app(OnlineContentCacheRepository::class)->store(
            'lrclib',
            OnlineContentType::Lyrics,
            new LyricsLookup(1, 'Track', 'Artist', 'Album', 180),
            OnlineContentStatus::NotFound,
            null,
            now()->addDay(),
        );

        $this->deleteJson('/api/settings/online-enrichment/cache')
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('cache.total', 0);
    }

    public function test_provider_checks_are_explicit_and_do_not_require_current_track_data(): void
    {
        Http::fake(['*lrclib.net*' => Http::response([
            'message' => 'Failed to find specified track',
        ], 404)]);

        $this->postJson('/api/settings/online-enrichment/providers/lastfm/test')
            ->assertOk()
            ->assertJsonPath('status', 'not_configured');
        $this->postJson('/api/settings/online-enrichment/providers/lrclib/test')
            ->assertOk()
            ->assertJsonPath('status', 'available');

        Http::assertSent(fn ($request): bool => $request['track_name'] === '__sonotheque_connection_test__');
    }
}
