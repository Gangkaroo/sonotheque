<?php

namespace Tests\Feature;

use App\Enums\OnlineContentStatus;
use App\Enums\OnlineContentType;
use App\Jobs\RefreshAlbumMusicianCredits;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\OnlineContentCache;
use App\Models\Track;
use App\Music\Enrichment\AlbumMusicianCreditManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlbumMusicianCreditsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_album_identity_cache_is_refetched_for_the_new_musician_lookup_version(): void
    {
        Queue::fake();
        $album = $this->createAlbumWithTrack();
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        OnlineContentCache::create([
            'provider' => 'musicbrainz',
            'resource_type' => OnlineContentType::Album,
            'lookup_hash' => hash('sha256', 'old-album-identity'),
            'lookup' => ['albumId' => $album->id],
            'status' => OnlineContentStatus::Ready,
            'payload' => ['providerReference' => self::RELEASE_ID],
            'fetched_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->getJson("/api/enrichment/albums/{$album->id}/musicians")
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('lookupVersion', AlbumMusicianCreditManager::LOOKUP_VERSION);

        $this->assertDatabaseHas('album_musician_enrichments', [
            'album_id' => $album->id,
            'lookup_version' => AlbumMusicianCreditManager::LOOKUP_VERSION,
            'status' => OnlineContentStatus::Pending->value,
        ]);
        Queue::assertPushed(
            RefreshAlbumMusicianCredits::class,
            fn (RefreshAlbumMusicianCredits $job): bool => $job->albumId === $album->id,
        );
    }

    public function test_musicbrainz_performance_relationships_are_normalized_and_reused(): void
    {
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        $album = $this->createAlbumWithTrack();
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        Http::fake(['*musicbrainz.org*' => Http::response([
            'id' => self::RELEASE_ID,
            'title' => 'Example Album',
            'relations' => [[
                'type' => 'conductor',
                'artist' => [
                    'id' => '11111111-1111-4111-8111-111111111111',
                    'name' => 'Release Conductor',
                    'sort-name' => 'Conductor, Release',
                    'type' => 'Person',
                ],
            ], [
                'type' => 'member of band',
                'artist' => [
                    'id' => '22222222-2222-4222-8222-222222222222',
                    'name' => 'Unrelated Member',
                ],
            ]],
            'media' => [[
                'position' => 1,
                'tracks' => [[
                    'id' => self::RELEASE_TRACK_ID,
                    'position' => 1,
                    'recording' => [
                        'id' => self::RECORDING_ID,
                        'relations' => [[
                            'type' => 'instrument',
                            'attributes' => ['guitar', 'guest'],
                            'attribute-credits' => ['guitar' => 'lead guitar'],
                            'target-credit' => 'J. Player',
                            'artist' => [
                                'id' => '33333333-3333-4333-8333-333333333333',
                                'name' => 'Jamie Player',
                                'sort-name' => 'Player, Jamie',
                                'disambiguation' => 'session guitarist',
                                'type' => 'Person',
                            ],
                        ]],
                    ],
                ]],
            ]],
        ])]);

        app(AlbumMusicianCreditManager::class)->refresh($album->id);

        $this->getJson("/api/enrichment/albums/{$album->id}/musicians")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('data.releaseId', self::RELEASE_ID)
            ->assertJsonCount(2, 'data.musicians')
            ->assertJsonPath('data.musicians.0.name', 'Release Conductor')
            ->assertJsonPath('data.musicians.0.credits.0.scope', 'release')
            ->assertJsonPath('data.musicians.1.name', 'Jamie Player')
            ->assertJsonPath('data.musicians.1.credits.0.role', 'lead guitar')
            ->assertJsonPath('data.musicians.1.credits.0.guest', true)
            ->assertJsonPath('data.musicians.1.credits.0.tracks.0.title', 'Example Track');

        $this->assertDatabaseCount('musicians', 2);
        $this->assertDatabaseCount('album_musician_credits', 2);
        $this->assertDatabaseMissing('musicians', ['name' => 'Unrelated Member']);
        Http::assertSentCount(1);

        app(AlbumMusicianCreditManager::class)->forAlbum($album);
        Http::assertSentCount(1);
    }

    public function test_album_wide_and_track_scoped_versions_of_the_same_role_remain_distinct(): void
    {
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        $album = $this->createAlbumWithTrack();
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        $musicianId = '77777777-7777-4777-8777-777777777777';
        $relation = [
            'type' => 'instrument',
            'attributes' => ['guitar'],
            'artist' => [
                'id' => $musicianId,
                'name' => 'Scope Player',
            ],
        ];
        Http::fake(['*musicbrainz.org*' => Http::response([
            'id' => self::RELEASE_ID,
            'relations' => [$relation],
            'media' => [[
                'position' => 1,
                'tracks' => [[
                    'id' => self::RELEASE_TRACK_ID,
                    'position' => 1,
                    'recording' => [
                        'id' => self::RECORDING_ID,
                        'relations' => [$relation],
                    ],
                ]],
            ]],
        ])]);

        app(AlbumMusicianCreditManager::class)->refresh($album->id);

        $response = $this->getJson("/api/enrichment/albums/{$album->id}/musicians")
            ->assertOk()
            ->assertJsonCount(1, 'data.musicians')
            ->assertJsonCount(2, 'data.musicians.0.credits');
        $this->assertEqualsCanonicalizing(
            ['recording', 'release'],
            $response->json('data.musicians.0.credits.*.scope'),
        );
    }

    public function test_musician_lookup_retries_release_search_for_punctuation_variants(): void
    {
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        $album = $this->createAlbumWithTrack(false);
        $album->primaryArtist()->update([
            'name' => 'Dream Theater',
            'sort_name' => 'Dream Theater',
            'browse_initial' => 'D',
        ]);
        $album->update([
            'title' => 'Metropolis Pt. 2 - Scenes From A Memory',
            'sort_title' => 'Metropolis Pt. 2 - Scenes From A Memory',
        ]);
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        Http::fakeSequence()
            ->push(['releases' => []])
            ->push(['releases' => [[
                'id' => self::RELEASE_ID,
                'title' => 'Metropolis, Pt. 2: Scenes From a Memory',
                'artist-credit' => [['name' => 'Dream Theater']],
                'score' => 100,
            ]]])
            ->push([
                'id' => self::RELEASE_ID,
                'title' => 'Metropolis, Pt. 2: Scenes From a Memory',
                'relations' => [[
                    'type' => 'instrument',
                    'attributes' => ['guitar'],
                    'artist' => [
                        'id' => '99999999-9999-4999-8999-999999999999',
                        'name' => 'John Player',
                    ],
                ]],
                'media' => [],
            ]);

        app(AlbumMusicianCreditManager::class)->refresh($album->id);

        $this->getJson("/api/enrichment/albums/{$album->id}/musicians")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('data.musicians.0.name', 'John Player');
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => is_string($request['query'] ?? null)
            && str_contains(
                $request['query'],
                'release:(Metropolis Pt 2 Scenes From A Memory)',
            ));
    }

    public function test_a_single_discogs_release_linked_by_musicbrainz_is_imported_without_an_owned_copy(): void
    {
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        $album = $this->createAlbumWithTrack();
        ApplicationSetting::current()->update([
            'online_information_enabled' => true,
            'discogs_personal_access_token' => 'discogs-token',
            'discogs_username' => 'collector',
            'discogs_user_id' => 123,
            'discogs_connected_at' => now(),
        ]);
        Http::fake([
            '*musicbrainz.org*' => Http::response([
                'id' => self::RELEASE_ID,
                'title' => 'Example Album',
                'relations' => [[
                    'type' => 'discogs',
                    'url' => ['resource' => 'https://www.discogs.com/release/789-Example-Release'],
                ], [
                    'type' => 'discogs',
                    'url' => ['resource' => 'https://www.discogs.com/master/456-Example-Master'],
                ]],
                'media' => [],
            ]),
            'api.discogs.com/releases/789*' => Http::response([
                'id' => 789,
                'title' => 'Example Album',
                'artists_sort' => 'Example Artist',
                'uri' => '/release/789-Example-Release',
                'extraartists' => [[
                    'id' => 801,
                    'name' => 'Discogs Session Player',
                    'role' => 'Guitar',
                ]],
            ]),
        ]);

        app(AlbumMusicianCreditManager::class)->refresh($album->id);

        $this->getJson("/api/albums/{$album->id}/musician-credits")
            ->assertOk()
            ->assertJsonCount(1, 'discogs.options')
            ->assertJsonPath('discogs.options.0.sourceType', 'musicbrainz')
            ->assertJsonPath('discogs.options.0.releaseId', 789)
            ->assertJsonPath('discogs.selectedKey', 'musicbrainz:789')
            ->assertJsonPath('discogs.selectedOwnedCopyId', null)
            ->assertJsonPath('items.0.musician.name', 'Discogs Session Player');

        $this->assertDatabaseHas('album_discogs_musician_sources', [
            'album_id' => $album->id,
            'source_type' => 'musicbrainz',
            'owned_album_copy_id' => null,
            'release_id' => 789,
        ]);
    }

    public function test_an_ambiguous_release_can_be_selected_and_reused_for_the_credit_lookup(): void
    {
        Queue::fake();
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        $album = $this->createAlbumWithTrack(false);
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        $otherReleaseId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        Http::fakeSequence()
            ->push([
                'releases' => [[
                    'id' => self::RELEASE_ID,
                    'title' => 'Example Album',
                    'artist-credit' => [['name' => 'Example Artist']],
                    'score' => 100,
                    'date' => '2001-02-03',
                    'country' => 'DE',
                    'status' => 'Official',
                    'track-count' => 1,
                    'media' => [['format' => 'CD']],
                ], [
                    'id' => $otherReleaseId,
                    'title' => 'Example Album',
                    'artist-credit' => [['name' => 'Example Artist']],
                    'score' => 99,
                    'date' => '2002',
                    'country' => 'US',
                    'status' => 'Official',
                    'track-count' => 1,
                    'media' => [['format' => 'Vinyl']],
                ]],
            ])
            ->push([
                'id' => $otherReleaseId,
                'title' => 'Example Album',
                'relations' => [[
                    'type' => 'instrument',
                    'attributes' => ['piano'],
                    'artist' => [
                        'id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                        'name' => 'Selected Edition Player',
                    ],
                ]],
                'media' => [],
            ]);

        app(AlbumMusicianCreditManager::class)->refresh($album->id);

        $this->getJson("/api/enrichment/albums/{$album->id}/musicians")
            ->assertOk()
            ->assertJsonPath('status', 'ambiguous')
            ->assertJsonCount(2, 'data.candidateReleases')
            ->assertJsonPath('data.candidateReleases.0.country', 'DE')
            ->assertJsonPath('data.candidateReleases.1.id', $otherReleaseId);

        $this->putJson("/api/enrichment/albums/{$album->id}/musicians/release", [
            'releaseId' => $otherReleaseId,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('data.selectedReleaseId', $otherReleaseId);

        $this->assertDatabaseHas('album_musician_enrichments', [
            'album_id' => $album->id,
            'selected_release_id' => $otherReleaseId,
            'status' => OnlineContentStatus::Pending->value,
        ]);
        app(AlbumMusicianCreditManager::class)->refresh($album->id);

        $this->getJson("/api/enrichment/albums/{$album->id}/musicians")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('data.releaseId', $otherReleaseId)
            ->assertJsonPath('data.selectedReleaseId', $otherReleaseId)
            ->assertJsonPath('data.musicians.0.name', 'Selected Edition Player');
        Http::assertSentCount(2);
    }

    public function test_manual_credits_are_editable_and_visible_without_online_enrichment(): void
    {
        $album = $this->createAlbumWithTrack();
        $track = $album->tracks()->firstOrFail();

        $response = $this->postJson("/api/albums/{$album->id}/musician-credits", [
            'name' => 'Local Musician',
            'role' => 'synthesizer',
            'creditedAs' => 'L. Musician',
            'guest' => true,
            'trackIds' => [$track->id],
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.manual', true)
            ->assertJsonPath('items.0.role', 'synthesizer');
        $manualCreditId = $response->json('items.0.id');

        $this->getJson("/api/enrichment/albums/{$album->id}/musicians")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('data.providerStatus', 'disabled')
            ->assertJsonPath('data.musicians.0.name', 'Local Musician')
            ->assertJsonPath('data.musicians.0.credits.0.manual', true)
            ->assertJsonPath('data.musicians.0.credits.0.tracks.0.id', $track->id);

        $this->patchJson("/api/albums/{$album->id}/musician-credits/{$manualCreditId}", [
            'name' => 'Local Musician',
            'role' => 'piano',
            'additional' => true,
            'trackIds' => [],
        ])
            ->assertOk()
            ->assertJsonPath('items.0.role', 'piano')
            ->assertJsonPath('items.0.additional', true)
            ->assertJsonCount(0, 'items.0.tracks');

        $this->deleteJson("/api/albums/{$album->id}/musician-credits/{$manualCreditId}")
            ->assertOk()
            ->assertJsonCount(0, 'items');
        $this->assertDatabaseCount('manual_album_musician_credits', 0);
    }

    public function test_an_imported_credit_can_be_hidden_across_refreshes_and_restored(): void
    {
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        $album = $this->createAlbumWithTrack();
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        Http::fake(['*musicbrainz.org*' => Http::response([
            'id' => self::RELEASE_ID,
            'relations' => [[
                'type' => 'instrument',
                'attributes' => ['guitar'],
                'artist' => [
                    'id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
                    'name' => 'Imported Musician',
                ],
            ]],
            'media' => [],
        ])]);

        app(AlbumMusicianCreditManager::class)->refresh($album->id);
        $sourceKey = $this->getJson("/api/albums/{$album->id}/musician-credits")
            ->assertOk()
            ->assertJsonPath('items.0.hidden', false)
            ->json('items.0.sourceKey');

        $this->putJson("/api/albums/{$album->id}/musician-credits/suppressions/{$sourceKey}")
            ->assertOk()
            ->assertJsonPath('items.0.hidden', true);
        $this->getJson("/api/enrichment/albums/{$album->id}/musicians")
            ->assertOk()
            ->assertJsonCount(0, 'data.musicians');

        app(AlbumMusicianCreditManager::class)->refresh($album->id);
        $this->getJson("/api/enrichment/albums/{$album->id}/musicians")
            ->assertOk()
            ->assertJsonCount(0, 'data.musicians');

        $this->deleteJson("/api/albums/{$album->id}/musician-credits/suppressions/{$sourceKey}")
            ->assertOk()
            ->assertJsonPath('items.0.hidden', false);
        $this->getJson("/api/enrichment/albums/{$album->id}/musicians")
            ->assertOk()
            ->assertJsonPath('data.musicians.0.name', 'Imported Musician');
    }

    public function test_manual_credit_track_scope_cannot_cross_album_boundaries(): void
    {
        $album = $this->createAlbumWithTrack();
        $otherAlbum = $this->createAlbumWithTrack(true, 'Other');

        $this->postJson("/api/albums/{$album->id}/musician-credits", [
            'name' => 'Local Musician',
            'role' => 'guitar',
            'trackIds' => [$otherAlbum->tracks()->firstOrFail()->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('trackIds');

        $this->assertDatabaseCount('manual_album_musician_credits', 0);
    }

    private const RELEASE_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const RECORDING_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const RELEASE_TRACK_ID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private function createAlbumWithTrack(bool $withIdentifiers = true, string $suffix = ''): Album
    {
        $artistName = $suffix === '' ? 'Example Artist' : "Example Artist {$suffix}";
        $albumTitle = $suffix === '' ? 'Example Album' : "Example Album {$suffix}";
        $rootPath = $suffix === '' ? 'D:/Music' : "D:/Music {$suffix}";
        $relativeAlbumPath = "{$artistName}/{$albumTitle}";
        $artist = Artist::create([
            'name' => $artistName,
            'sort_name' => $artistName,
            'browse_initial' => 'E',
        ]);
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => $rootPath,
            'path_hash' => hash('sha256', mb_strtolower($rootPath)),
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => $albumTitle,
            'sort_title' => $albumTitle,
            'relative_path' => $relativeAlbumPath,
            'relative_path_hash' => hash('sha256', mb_strtolower($relativeAlbumPath)),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => "{$relativeAlbumPath}/track.mp3",
            'relative_path_hash' => hash('sha256', mb_strtolower("{$relativeAlbumPath}/track.mp3")),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
            'raw_metadata' => $withIdentifiers ? [
                'comments' => [
                    'musicbrainz_albumid' => [self::RELEASE_ID],
                    'musicbrainz_trackid' => [self::RECORDING_ID],
                    'musicbrainz_releasetrackid' => [self::RELEASE_TRACK_ID],
                ],
            ] : [],
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

        return $album;
    }
}
