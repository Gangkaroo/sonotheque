<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Music\Enrichment\Data\AlbumLookup;
use App\Music\Enrichment\Data\ArtistLookup;
use App\Music\Enrichment\Data\LyricsLookup;
use App\Music\Enrichment\Providers\LastFmInformationProvider;
use App\Music\Enrichment\Providers\LrclibLyricsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnlineEnrichmentProvidersTest extends TestCase
{
    use RefreshDatabase;

    public function test_lastfm_normalizes_artist_and_album_information(): void
    {
        ApplicationSetting::current()->update(['lastfm_api_key' => 'test-key']);
        Http::fakeSequence()
            ->push([
                'artist' => [
                    'name' => 'Example Artist',
                    'mbid' => 'artist-mbid',
                    'url' => 'https://www.last.fm/music/Example+Artist',
                    'tags' => ['tag' => [['name' => 'Rock'], ['name' => 'Indie']]],
                    'bio' => ['summary' => '<p>An example biography.</p> Read more on Last.fm'],
                ],
            ])
            ->push([
                'album' => [
                    'name' => 'Example Album',
                    'artist' => 'Example Artist',
                    'mbid' => 'album-mbid',
                    'url' => 'https://www.last.fm/music/Example+Artist/Example+Album',
                    'tags' => ['tag' => ['name' => 'Alternative']],
                    'wiki' => ['summary' => '<b>An album summary.</b>'],
                ],
            ]);

        $provider = app(LastFmInformationProvider::class);
        $artist = $provider->fetchArtist(new ArtistLookup(1, 'Example Artist', language: 'de'));
        $album = $provider->fetchAlbum(new AlbumLookup(2, 'Example Album', 'Example Artist', language: 'de'));

        $this->assertSame('An example biography.', $artist?->biography);
        $this->assertSame(['Rock', 'Indie'], $artist?->tags);
        $this->assertSame('An album summary.', $album?->summary);
        $this->assertSame(['Alternative'], $album?->tags);

        Http::assertSent(fn ($request): bool => $request['method'] === 'artist.getInfo'
            && $request['lang'] === 'de'
            && $request['api_key'] === 'test-key');
        Http::assertSent(fn ($request): bool => $request['method'] === 'album.getInfo'
            && $request['album'] === 'Example Album');
    }

    public function test_lrclib_uses_the_exact_track_signature(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 42,
            'trackName' => 'Example Track',
            'artistName' => 'Example Artist',
            'albumName' => 'Example Album',
            'duration' => 123,
            'instrumental' => false,
            'plainLyrics' => "First line\nSecond line",
            'syncedLyrics' => '[00:01.00] First line',
        ])]);

        $lyrics = app(LrclibLyricsProvider::class)->fetchLyrics(new LyricsLookup(
            3,
            'Example Track',
            'Example Artist',
            'Example Album',
            123,
        ));

        $this->assertSame("First line\nSecond line", $lyrics?->plainLyrics);
        $this->assertSame('42', $lyrics?->providerReference);
        $this->assertFalse($lyrics?->instrumental ?? true);
        Http::assertSent(fn ($request): bool => $request['track_name'] === 'Example Track'
            && $request['artist_name'] === 'Example Artist'
            && $request['album_name'] === 'Example Album'
            && (int) $request['duration'] === 123);
    }
}
