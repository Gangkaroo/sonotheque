<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnlineEnrichmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_enrichment_never_contacts_a_provider(): void
    {
        $track = $this->createTrack();
        Http::fake();

        $this->getJson("/api/enrichment/tracks/{$track->id}/information")
            ->assertOk()
            ->assertJsonPath('artist.status', 'disabled')
            ->assertJsonPath('album.status', 'disabled');
        $this->getJson("/api/enrichment/tracks/{$track->id}/lyrics")
            ->assertOk()
            ->assertJsonPath('status', 'disabled');

        Http::assertNothingSent();
    }

    public function test_enrichment_is_normalized_and_reused_from_the_cache(): void
    {
        $track = $this->createTrack();
        ApplicationSetting::current()->update([
            'lastfm_api_key' => 'test-key',
            'online_information_enabled' => true,
            'online_lyrics_enabled' => true,
        ]);
        Http::fake([
            '*audioscrobbler.com*' => Http::sequence()
                ->push([
                    'artist' => [
                        'name' => 'Example Artist',
                        'url' => 'https://www.last.fm/music/Example+Artist',
                        'bio' => ['summary' => 'Biography'],
                        'tags' => ['tag' => []],
                    ],
                ])
                ->push([
                    'album' => [
                        'name' => 'Example Album',
                        'artist' => 'Example Artist',
                        'url' => 'https://www.last.fm/music/Example+Artist/Example+Album',
                        'wiki' => ['summary' => 'Summary'],
                        'tags' => ['tag' => []],
                    ],
                ]),
            '*lrclib.net*' => Http::response([
                'id' => 99,
                'instrumental' => false,
                'plainLyrics' => 'Lyrics',
                'syncedLyrics' => null,
            ]),
        ]);

        $informationUrl = "/api/enrichment/tracks/{$track->id}/information?language=en";
        $this->getJson($informationUrl)
            ->assertOk()
            ->assertJsonPath('artist.status', 'ready')
            ->assertJsonPath('artist.cached', false)
            ->assertJsonPath('artist.data.biography', 'Biography')
            ->assertJsonPath('album.data.summary', 'Summary');
        $this->getJson($informationUrl)
            ->assertOk()
            ->assertJsonPath('artist.cached', true)
            ->assertJsonPath('album.cached', true);

        $lyricsUrl = "/api/enrichment/tracks/{$track->id}/lyrics";
        $this->getJson($lyricsUrl)
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('cached', false)
            ->assertJsonPath('data.plainLyrics', 'Lyrics');
        $this->getJson($lyricsUrl)
            ->assertOk()
            ->assertJsonPath('cached', true);

        Http::assertSentCount(3);
    }

    public function test_provider_connection_errors_return_a_safe_specific_category(): void
    {
        $track = $this->createTrack();
        ApplicationSetting::current()->update(['online_lyrics_enabled' => true]);
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
        ));

        $this->getJson("/api/enrichment/tracks/{$track->id}/lyrics")
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('errorCode', 'tls_certificate')
            ->assertJsonPath('data', null);
    }

    public function test_musicbrainz_identity_uses_identifiers_retained_during_the_scan(): void
    {
        config(['music-library.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        $track = $this->createTrack([
            'comments' => [
                'musicbrainz_albumartistid' => ['5b11f4ce-a62d-471e-81fc-a69a8278c7da'],
                'musicbrainz_albumid' => ['18d5d0ca-1107-4df2-9d51-df1c5fe57490'],
            ],
        ]);
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        Http::fakeSequence()
            ->push([
                'id' => '5b11f4ce-a62d-471e-81fc-a69a8278c7da',
                'name' => 'Example Artist',
                'country' => 'DE',
            ])
            ->push([
                'id' => '18d5d0ca-1107-4df2-9d51-df1c5fe57490',
                'title' => 'Example Album',
                'date' => '2020-03-06',
                'artist-credit' => [['name' => 'Example Artist']],
                'release-group' => ['primary-type' => 'Album'],
            ]);

        $this->getJson("/api/enrichment/tracks/{$track->id}/identity")
            ->assertOk()
            ->assertJsonPath('artist.status', 'ready')
            ->assertJsonPath('artist.data.matchMethod', 'tag')
            ->assertJsonPath('artist.data.country', 'DE')
            ->assertJsonPath('album.status', 'ready')
            ->assertJsonPath('album.data.releaseDate', '2020-03-06');
    }

    /** @param array<string, mixed> $rawMetadata */
    private function createTrack(array $rawMetadata = []): Track
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
            'raw_metadata' => $rawMetadata,
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

        return $track;
    }
}
