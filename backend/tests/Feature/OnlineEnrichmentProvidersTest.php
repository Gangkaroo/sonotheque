<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Music\Enrichment\AmbiguousEnrichmentMatchException;
use App\Music\Enrichment\Data\AlbumLookup;
use App\Music\Enrichment\Data\ArtistLookup;
use App\Music\Enrichment\Data\LyricsLookup;
use App\Music\Enrichment\Providers\LastFmInformationProvider;
use App\Music\Enrichment\Providers\LrclibLyricsProvider;
use App\Music\Enrichment\Providers\MusicBrainzInformationProvider;
use App\Music\Enrichment\Providers\WikimediaArtistImageProvider;
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
                    'bio' => [
                        'summary' => '<p>A short biography.</p> Read more on Last.fm',
                        'content' => '<p>An example biography.</p> Read more on Last.fm',
                    ],
                ],
            ])
            ->push([
                'album' => [
                    'name' => 'Example Album',
                    'artist' => 'Example Artist',
                    'mbid' => 'album-mbid',
                    'url' => 'https://www.last.fm/music/Example+Artist/Example+Album',
                    'tags' => ['tag' => ['name' => 'Alternative']],
                    'wiki' => [
                        'summary' => '<b>A short album summary.</b>',
                        'content' => '<b>An album summary.</b>',
                    ],
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

    public function test_wikimedia_resolves_an_attributed_artist_image_by_musicbrainz_id(): void
    {
        Http::fakeSequence()
            ->push([
                'results' => ['bindings' => [[
                    'item' => ['value' => 'http://www.wikidata.org/entity/Q123'],
                    'image' => ['value' => 'http://commons.wikimedia.org/wiki/Special:FilePath/Example%20Artist.jpg'],
                ]]],
            ])
            ->push([
                'query' => ['pages' => [[
                    'imageinfo' => [[
                        'thumburl' => 'https://upload.wikimedia.org/example-artist.jpg',
                        'thumbwidth' => 600,
                        'thumbheight' => 400,
                        'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Example_Artist.jpg',
                        'extmetadata' => [
                            'Artist' => ['value' => '<a>Example Photographer</a>'],
                            'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                            'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
                        ],
                    ]],
                ]]],
            ]);

        $image = app(WikimediaArtistImageProvider::class)->fetchArtistImage(new ArtistLookup(
            1,
            'Example Artist',
            ['musicbrainz_artist' => '5b11f4ce-a62d-471e-81fc-a69a8278c7da'],
        ));

        $this->assertSame('https://upload.wikimedia.org/example-artist.jpg', $image?->imageUrl);
        $this->assertSame('Example Photographer', $image?->author);
        $this->assertSame('CC BY-SA 4.0', $image?->licenseName);
        $this->assertSame('Wikimedia Commons', $image?->attribution->label);
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

    public function test_musicbrainz_prefers_tagged_identifiers(): void
    {
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        Http::fakeSequence()
            ->push([
                'id' => '5b11f4ce-a62d-471e-81fc-a69a8278c7da',
                'name' => 'Example Artist',
                'country' => 'DE',
                'life-span' => ['begin' => '1999', 'end' => null],
                'tags' => [['name' => 'indie rock']],
            ])
            ->push([
                'id' => '18d5d0ca-1107-4df2-9d51-df1c5fe57490',
                'title' => 'Example Album',
                'date' => '2020-03-06',
                'artist-credit' => [['name' => 'Example Artist']],
                'label-info' => [[
                    'label' => ['name' => 'Example Records'],
                    'catalog-number' => 'EX-001',
                ]],
                'release-group' => ['primary-type' => 'Album'],
            ]);

        $provider = app(MusicBrainzInformationProvider::class);
        $artist = $provider->fetchArtist(new ArtistLookup(1, 'Example Artist', [
            'musicbrainz_artist' => '5b11f4ce-a62d-471e-81fc-a69a8278c7da',
        ]));
        $album = $provider->fetchAlbum(new AlbumLookup(2, 'Example Album', 'Example Artist', externalIds: [
            'musicbrainz_release' => '18d5d0ca-1107-4df2-9d51-df1c5fe57490',
        ]));

        $this->assertSame('tag', $artist?->matchMethod);
        $this->assertSame(100, $artist?->matchConfidence);
        $this->assertSame('DE', $artist?->country);
        $this->assertSame('2020-03-06', $album?->releaseDate);
        $this->assertSame('Example Records', $album?->label);
        $this->assertSame([[
            'name' => 'Example Records',
            'catalogNumber' => 'EX-001',
        ]], $album?->recordLabels);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/artist/5b11f4ce-a62d-471e-81fc-a69a8278c7da'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/release/18d5d0ca-1107-4df2-9d51-df1c5fe57490'));
    }

    public function test_musicbrainz_accepts_only_a_clear_exact_search_match(): void
    {
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        Http::fake(['*' => Http::response([
            'artists' => [
                ['id' => '5b11f4ce-a62d-471e-81fc-a69a8278c7da', 'name' => 'Example Artist', 'score' => 100],
                ['id' => '65f4f0c5-ef9e-490c-aee3-909e7ae6b2ab', 'name' => 'Example Artists', 'score' => 70],
            ],
        ])]);

        $artist = app(MusicBrainzInformationProvider::class)
            ->fetchArtist(new ArtistLookup(1, 'Example Artist'));

        $this->assertSame('search', $artist?->matchMethod);
        $this->assertSame(100, $artist?->matchConfidence);
    }

    public function test_musicbrainz_retries_album_search_with_terms_for_punctuation_variants(): void
    {
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        Http::fakeSequence()
            ->push(['release-groups' => []])
            ->push(['release-groups' => [[
                'id' => 'd8ae7015-d78b-307e-bcde-5b0dd1e61528',
                'title' => 'Metropolis, Pt. 2: Scenes From a Memory',
                'artist-credit' => [['name' => 'Dream Theater']],
                'score' => 100,
                'first-release-date' => '1999-10-26',
                'primary-type' => 'Album',
            ], [
                'id' => 'ed4ae3fc-fffb-3eff-9aa3-88a7089ffff7',
                'title' => 'Scenes From a Memory',
                'artist-credit' => [['name' => 'Dream Theater']],
                'score' => 41,
            ]]]);

        $album = app(MusicBrainzInformationProvider::class)->fetchAlbum(new AlbumLookup(
            2,
            'Metropolis Pt. 2 - Scenes From A Memory',
            'Dream Theater',
        ));

        $this->assertSame('Metropolis, Pt. 2: Scenes From a Memory', $album?->title);
        $this->assertSame(100, $album?->matchConfidence);
        $this->assertSame('1999-10-26', $album?->releaseDate);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains(
            (string) $request['query'],
            'releasegroup:(Metropolis Pt 2 Scenes From A Memory)',
        ));
    }

    public function test_musicbrainz_rejects_ambiguous_search_results(): void
    {
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        Http::fake(['*' => Http::response([
            'artists' => [
                ['id' => '5b11f4ce-a62d-471e-81fc-a69a8278c7da', 'name' => 'Example Artist', 'score' => 100],
                ['id' => '65f4f0c5-ef9e-490c-aee3-909e7ae6b2ab', 'name' => 'Example Artist', 'score' => 95],
            ],
        ])]);

        $this->expectException(AmbiguousEnrichmentMatchException::class);

        app(MusicBrainzInformationProvider::class)->fetchArtist(new ArtistLookup(1, 'Example Artist'));
    }
}
