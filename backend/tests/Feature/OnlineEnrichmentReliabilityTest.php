<?php

namespace Tests\Feature;

use App\Enums\OnlineContentStatus;
use App\Enums\OnlineContentType;
use App\Jobs\RefreshOnlineEnrichment;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use App\Music\Enrichment\Data\LyricsLookup;
use App\Music\Enrichment\EnrichmentProviderException;
use App\Music\Enrichment\OnlineContentCacheRepository;
use App\Music\Enrichment\OnlineEnrichmentManager;
use App\Music\Enrichment\ProviderRequestGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OnlineEnrichmentReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_content_is_returned_while_one_unique_refresh_is_dispatched(): void
    {
        [$track, $lookup] = $this->createTrackAndLookup();
        ApplicationSetting::current()->update(['online_lyrics_enabled' => true]);
        $repository = app(OnlineContentCacheRepository::class);
        $repository->store(
            'lrclib',
            OnlineContentType::Lyrics,
            $lookup,
            OnlineContentStatus::Ready,
            $this->lyricsPayload('Cached lyrics'),
            now()->subMinute(),
            now()->addDay(),
        );
        Queue::fake();
        Http::fake();

        $this->getJson("/api/enrichment/tracks/{$track->id}/lyrics")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('stale', true)
            ->assertJsonPath('data.plainLyrics', 'Cached lyrics');

        Queue::assertPushed(RefreshOnlineEnrichment::class, 1);
        Http::assertNothingSent();
    }

    public function test_lock_contention_returns_pending_without_duplicate_provider_request(): void
    {
        [$track, $lookup] = $this->createTrackAndLookup();
        ApplicationSetting::current()->update(['online_lyrics_enabled' => true]);
        config(['sonotheque.enrichment.lock_wait_seconds' => 1]);
        $repository = app(OnlineContentCacheRepository::class);
        $lock = Cache::lock($repository->lockKey('lrclib', OnlineContentType::Lyrics, $lookup), 10);
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            $this->getJson("/api/enrichment/tracks/{$track->id}/lyrics")
                ->assertOk()
                ->assertJsonPath('status', 'pending');
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_provider_request_gate_enforces_the_configured_limit(): void
    {
        config(['sonotheque.enrichment.providers.lrclib.max_requests_per_minute' => 1]);
        RateLimiter::clear('online-enrichment:provider:lrclib');
        $gate = app(ProviderRequestGate::class);

        $this->assertSame('first', $gate->run('lrclib', fn (): string => 'first'));

        try {
            $gate->run('lrclib', fn (): string => 'second');
            $this->fail('The second provider request should have been throttled.');
        } catch (EnrichmentProviderException $exception) {
            $this->assertSame('rate_limited', $exception->errorCode);
            $this->assertGreaterThan(0, $exception->retryAfterSeconds);
        }
    }

    public function test_failed_background_refresh_keeps_stale_content_and_records_backoff(): void
    {
        RateLimiter::clear('online-enrichment:provider:lrclib');
        [, $lookup] = $this->createTrackAndLookup();
        ApplicationSetting::current()->update(['online_lyrics_enabled' => true]);
        $repository = app(OnlineContentCacheRepository::class);
        $repository->store(
            'lrclib',
            OnlineContentType::Lyrics,
            $lookup,
            OnlineContentStatus::Ready,
            $this->lyricsPayload('Still useful'),
            now()->subMinute(),
            now()->addDay(),
        );
        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;

            throw new ConnectionException('Connection failed');
        });

        app(OnlineEnrichmentManager::class)->refreshLookup(
            'lrclib',
            OnlineContentType::Lyrics,
            $lookup->cachePayload(),
        );

        $cache = $repository->find('lrclib', OnlineContentType::Lyrics, $lookup);
        $this->assertSame(OnlineContentStatus::Ready, $cache?->status);
        $this->assertSame('Still useful', $cache?->payload['plainLyrics']);
        $this->assertSame(1, $cache?->failure_count);
        $this->assertSame('connection', $cache?->last_error_code);
        $this->assertTrue($cache?->retry_after?->isFuture() ?? false);

        app(OnlineEnrichmentManager::class)->refreshLookup(
            'lrclib',
            OnlineContentType::Lyrics,
            $lookup->cachePayload(),
        );
        $this->assertSame(1, $requests);
    }

    /** @return array{Track, LyricsLookup} */
    private function createTrackAndLookup(): array
    {
        $artist = Artist::create([
            'name' => 'Example Artist',
            'sort_name' => 'Example Artist',
            'browse_initial' => 'E',
        ]);
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/Music',
            'path_hash' => hash('sha256', 'd:/music'),
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Example Album',
            'sort_title' => 'Example Album',
            'relative_path' => 'Example Artist/Example Album',
            'relative_path_hash' => hash('sha256', 'example artist/example album'),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => 'Example Artist/Example Album/track.mp3',
            'relative_path_hash' => hash('sha256', 'example artist/example album/track.mp3'),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Example Track',
            'sort_title' => 'Example Track',
            'duration_ms' => 123000,
            'disc_number' => 1,
            'track_number' => 1,
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        return [$track, new LyricsLookup(
            $track->id,
            $track->title,
            $artist->name,
            $album->title,
            123,
        )];
    }

    /** @return array<string, mixed> */
    private function lyricsPayload(string $lyrics): array
    {
        return [
            'plainLyrics' => $lyrics,
            'synchronizedLyrics' => null,
            'language' => null,
            'instrumental' => false,
            'providerReference' => '1',
            'attribution' => [
                'provider' => 'lrclib',
                'label' => 'LRCLIB',
                'sourceUrl' => 'https://lrclib.net/api/get/1',
            ],
        ];
    }
}
