<?php

namespace Tests\Feature;

use App\Enums\OnlineContentStatus;
use App\Enums\OnlineContentType;
use App\Music\Enrichment\Data\ArtistLookup;
use App\Music\Enrichment\OnlineContentCacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnlineContentCacheRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_and_finds_normalized_provider_content(): void
    {
        $repository = $this->app->make(OnlineContentCacheRepository::class);
        $lookup = new ArtistLookup(10, 'Example Artist', [
            'musicbrainz' => 'mbid',
            'lastfm' => 'lastfm-name',
        ]);

        $stored = $repository->store(
            provider: 'example',
            type: OnlineContentType::Artist,
            lookup: $lookup,
            status: OnlineContentStatus::Ready,
            payload: ['biography' => 'Artist biography'],
            expiresAt: now()->addDay(),
            staleUntil: now()->addDays(2),
            providerReference: 'provider-artist-id',
            sourceUrl: 'https://example.test/artists/provider-artist-id',
        );

        $found = $repository->find('example', OnlineContentType::Artist, $lookup);

        $this->assertTrue($stored->is($found));
        $this->assertSame(OnlineContentType::Artist, $found->resource_type);
        $this->assertSame(OnlineContentStatus::Ready, $found->status);
        $this->assertSame(['biography' => 'Artist biography'], $found->payload);
        $this->assertSame('provider-artist-id', $found->provider_reference);
        $this->assertNotNull($found->expires_at);
    }

    public function test_lookup_hash_is_independent_of_associative_key_order(): void
    {
        $repository = $this->app->make(OnlineContentCacheRepository::class);
        $first = new ArtistLookup(10, 'Example Artist', [
            'musicbrainz' => 'mbid',
            'lastfm' => 'lastfm-name',
        ]);
        $second = new ArtistLookup(10, 'Example Artist', [
            'lastfm' => 'lastfm-name',
            'musicbrainz' => 'mbid',
        ]);

        $this->assertSame($repository->lookupHash($first), $repository->lookupHash($second));
    }
}
