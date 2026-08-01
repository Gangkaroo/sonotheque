<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\OwnedAlbumCopy;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AlbumDiscogsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        ApplicationSetting::current()->update([
            'discogs_personal_access_token' => 'discogs-token',
            'discogs_username' => 'collector',
            'discogs_user_id' => 123,
            'discogs_connected_at' => now(),
        ]);
    }

    public function test_it_searches_exact_discogs_releases_with_album_refinements(): void
    {
        [$album] = $this->createOwnedAlbum();
        $imageUrl = 'https://i.discogs.com/example.jpg';
        $imageHash = hash('sha256', $imageUrl);
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Http::fake([
            'api.discogs.com/database/search*' => Http::response([
                'results' => [
                    [
                        'id' => 456,
                        'type' => 'release',
                        'master_id' => 78,
                        'title' => 'Artist - Album',
                        'year' => '2001',
                        'country' => 'Germany',
                        'format' => ['CD', 'Album'],
                        'label' => ['Example Records'],
                        'catno' => 'EX-123',
                        'thumb' => $imageUrl,
                        'uri' => '/release/456-Artist-Album',
                        'user_data' => ['in_collection' => true],
                    ],
                    ['id' => 79, 'type' => 'master', 'title' => 'Artist - Album'],
                ],
            ]),
            $imageUrl => Http::response($image, 200, ['Content-Type' => 'image/png']),
        ]);

        $this->getJson("/api/albums/{$album->id}/discogs/candidates?artist=Artist&title=Album&year=2001&format=CD")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.releaseId', 456)
            ->assertJsonPath('items.0.masterId', 78)
            ->assertJsonPath('items.0.catalogNumber', 'EX-123')
            ->assertJsonPath('items.0.inCollection', true)
            ->assertJsonPath('items.0.thumbnailUrl', "/api/discogs/images/{$imageHash}")
            ->assertJsonPath('items.0.webUrl', 'https://www.discogs.com/release/456-Artist-Album');

        $this->get("/api/discogs/images/{$imageHash}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertContent($image);
        $this->get("/api/discogs/images/{$imageHash}")->assertOk()->assertContent($image);
        Storage::disk('local')->assertExists("discogs-images/{$imageHash}.image");

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://api.discogs.com/database/search?',
        ) && $request['type'] === 'release'
            && $request['artist'] === 'Artist'
            && $request['release_title'] === 'Album'
            && $request['year'] === '2001'
            && $request['format'] === 'CD');
        Http::assertSentCount(2);
    }

    public function test_it_does_not_proxy_unregistered_or_untrusted_discogs_images(): void
    {
        [$album] = $this->createOwnedAlbum();
        Http::fake(['api.discogs.com/database/search*' => Http::response([
            'results' => [[
                'id' => 456,
                'type' => 'release',
                'title' => 'Artist - Album',
                'thumb' => 'https://example.com/untrusted.jpg',
            ]],
        ])]);

        $this->getJson("/api/albums/{$album->id}/discogs/candidates?artist=Artist&title=Album")
            ->assertOk()
            ->assertJsonPath('items.0.thumbnailUrl', null);
        $this->get('/api/discogs/images/'.str_repeat('a', 64))->assertNotFound();

        Http::assertSentCount(1);
    }

    public function test_it_links_and_unlinks_an_owned_copy_without_changing_ownership_data(): void
    {
        [$album, $copy] = $this->createOwnedAlbum();
        Http::fake([
            'api.discogs.com/releases/456' => Http::response([
                'id' => 456,
                'master_id' => 78,
            ]),
            'api.discogs.com/users/collector/collection/releases/456*' => Http::response([
                'pagination' => ['items' => 1],
                'releases' => [[
                    'id' => 456,
                    'instance_id' => 991,
                    'folder_id' => 2,
                ]],
            ]),
            'api.discogs.com/users/collector/collection/folders' => Http::response([
                'folders' => [['id' => 2, 'name' => 'CDs', 'count' => 1]],
            ]),
        ]);

        $this->putJson("/api/albums/{$album->id}/owned-copies/{$copy->id}/discogs", [
            'releaseId' => 456,
        ])
            ->assertOk()
            ->assertJsonPath('ownedCopies.0.provider', 'discogs')
            ->assertJsonPath('ownedCopies.0.externalReleaseId', 456)
            ->assertJsonPath('ownedCopies.0.externalCollectionInstanceId', 991);

        $this->assertDatabaseHas('owned_album_copies', [
            'id' => $copy->id,
            'purchase_source' => 'Local store',
            'provider' => 'discogs',
            'external_release_id' => 456,
            'external_master_id' => 78,
            'external_collection_instance_id' => 991,
            'external_folder_id' => 2,
        ]);

        $this->getJson("/api/albums/{$album->id}/owned-copies/{$copy->id}/discogs")
            ->assertOk()
            ->assertJsonPath('release.id', 456)
            ->assertJsonPath('collectionInstance.instanceId', 991)
            ->assertJsonPath('collectionInstance.folderName', 'CDs');

        $this->deleteJson("/api/albums/{$album->id}/owned-copies/{$copy->id}/discogs")
            ->assertOk()
            ->assertJsonPath('ownedCopies.0.provider', null)
            ->assertJsonPath('ownedCopies.0.externalReleaseId', null);

        $this->assertDatabaseHas('owned_album_copies', [
            'id' => $copy->id,
            'purchase_source' => 'Local store',
            'provider' => null,
            'external_release_id' => null,
        ]);
    }

    public function test_it_requires_a_choice_when_a_release_has_multiple_collection_copies(): void
    {
        [$album, $copy] = $this->createOwnedAlbum();
        Http::fake([
            'api.discogs.com/releases/456' => Http::response([
                'id' => 456,
                'master_id' => 78,
                'title' => 'Album',
                'artists_sort' => 'Artist',
                'year' => 2001,
                'country' => 'Germany',
                'formats' => [['name' => 'CD', 'descriptions' => ['Album']]],
                'labels' => [['name' => 'Example Records', 'catno' => 'EX-123']],
                'uri' => 'https://www.discogs.com/release/456-Artist-Album',
            ]),
            'api.discogs.com/users/collector/collection/releases/456*' => Http::response([
                'releases' => [
                    ['instance_id' => 991, 'folder_id' => 2, 'date_added' => '2024-01-01T12:00:00-08:00'],
                    ['instance_id' => 992, 'folder_id' => 3, 'date_added' => '2025-02-02T12:00:00-08:00'],
                ],
            ]),
            'api.discogs.com/users/collector/collection/folders' => Http::response([
                'folders' => [
                    ['id' => 2, 'name' => 'CDs'],
                    ['id' => 3, 'name' => 'Box sets'],
                ],
            ]),
        ]);

        $this->getJson("/api/albums/{$album->id}/discogs/releases/456/collection-instances")
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.folderName', 'CDs')
            ->assertJsonPath('items.1.instanceId', 992);

        $this->putJson("/api/albums/{$album->id}/owned-copies/{$copy->id}/discogs", [
            'releaseId' => 456,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Choose which Discogs collection copy should be linked.');

        $this->putJson("/api/albums/{$album->id}/owned-copies/{$copy->id}/discogs", [
            'releaseId' => 456,
            'collectionInstanceId' => 992,
        ])
            ->assertOk()
            ->assertJsonPath('ownedCopies.0.externalCollectionInstanceId', 992)
            ->assertJsonPath('ownedCopies.0.externalFolderId', 3);

        $this->postJson("/api/albums/{$album->id}/owned-copies/{$copy->id}/discogs/refresh", [
            'collectionInstanceId' => 992,
        ])
            ->assertOk()
            ->assertJsonPath('ownedCopies.0.externalCollectionInstanceId', 992);

        $this->getJson("/api/albums/{$album->id}/owned-copies/{$copy->id}/discogs")
            ->assertOk()
            ->assertJsonPath('release.formats.0', 'CD (Album)')
            ->assertJsonPath('release.labels.0', 'Example Records')
            ->assertJsonPath('release.catalogNumber', 'EX-123')
            ->assertJsonPath('release.webUrl', 'https://www.discogs.com/release/456-Artist-Album')
            ->assertJsonPath('collectionInstance.folderName', 'Box sets');
    }

    public function test_it_does_not_link_a_copy_from_another_album(): void
    {
        [$album] = $this->createOwnedAlbum();
        [, $otherCopy] = $this->createOwnedAlbum('Other Album');

        $this->putJson("/api/albums/{$album->id}/owned-copies/{$otherCopy->id}/discogs", [
            'releaseId' => 456,
        ])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_linking_an_owned_release_imports_and_unlinking_removes_discogs_musician_credits(): void
    {
        [$album, $copy] = $this->createOwnedAlbum();
        $firstTrack = $this->createTrack($album, 'First Song', 1);
        $secondTrack = $this->createTrack($album, 'Second Song', 2);
        Http::fake([
            'api.discogs.com/releases/456' => Http::response([
                'id' => 456,
                'master_id' => 78,
                'title' => 'Album',
                'artists_sort' => 'Artist',
                'uri' => '/release/456-Artist-Album',
                'extraartists' => [[
                    'id' => 101,
                    'name' => 'Album Producer',
                    'role' => 'Producer',
                ]],
                'tracklist' => [
                    [
                        'position' => '1',
                        'title' => 'First Song',
                        'extraartists' => [[
                            'id' => 102,
                            'name' => 'Guest Singer',
                            'anv' => 'The Guest',
                            'role' => 'Vocals [Guest]',
                        ]],
                    ],
                    [
                        'position' => 'A2',
                        'title' => 'Second Song',
                        'extraartists' => [[
                            'id' => 103,
                            'name' => 'Guitar Player',
                            'role' => 'Guitar',
                        ]],
                    ],
                ],
            ]),
            'api.discogs.com/users/collector/collection/releases/456*' => Http::response([
                'releases' => [],
            ]),
        ]);

        $this->putJson("/api/albums/{$album->id}/owned-copies/{$copy->id}/discogs", [
            'releaseId' => 456,
        ])->assertOk();

        $this->assertDatabaseHas('album_discogs_musician_sources', [
            'album_id' => $album->id,
            'owned_album_copy_id' => $copy->id,
            'release_id' => 456,
        ]);
        $this->assertDatabaseHas('album_musician_credits', [
            'album_id' => $album->id,
            'track_id' => null,
            'provider' => 'discogs',
            'role' => 'Producer',
        ]);
        $this->assertDatabaseHas('album_musician_credits', [
            'album_id' => $album->id,
            'track_id' => $firstTrack->id,
            'provider' => 'discogs',
            'role' => 'Vocals [Guest]',
        ]);
        $this->assertDatabaseHas('album_musician_credits', [
            'album_id' => $album->id,
            'track_id' => $secondTrack->id,
            'provider' => 'discogs',
            'role' => 'Guitar',
        ]);

        $this->getJson("/api/albums/{$album->id}/musician-credits")
            ->assertOk()
            ->assertJsonPath('discogs.selectedOwnedCopyId', $copy->id)
            ->assertJsonPath('discogs.selectedReleaseId', 456)
            ->assertJsonCount(3, 'items');
        $this->getJson("/api/enrichment/albums/{$album->id}/musicians")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonCount(3, 'data.musicians');

        $this->deleteJson("/api/albums/{$album->id}/owned-copies/{$copy->id}/discogs")
            ->assertOk();
        $this->assertDatabaseMissing('album_discogs_musician_sources', ['album_id' => $album->id]);
        $this->assertDatabaseMissing('album_musician_credits', [
            'album_id' => $album->id,
            'provider' => 'discogs',
        ]);
    }

    public function test_a_user_can_choose_the_discogs_edition_used_for_musician_credits(): void
    {
        [$album, $firstCopy] = $this->createOwnedAlbum();
        $firstCopy->update(['provider' => 'discogs', 'external_release_id' => 456]);
        $secondCopy = $album->ownedCopies()->create([
            'is_physical' => true,
            'physical_format' => 'vinyl',
            'provider' => 'discogs',
            'external_release_id' => 789,
        ]);
        Http::fake(['api.discogs.com/releases/789' => Http::response([
            'id' => 789,
            'title' => 'Album',
            'artists_sort' => 'Artist',
            'uri' => '/release/789-Artist-Album',
            'extraartists' => [[
                'id' => 201,
                'name' => 'Vinyl Edition Player',
                'role' => 'Piano',
            ]],
        ])]);

        $this->getJson("/api/albums/{$album->id}/musician-credits")
            ->assertOk()
            ->assertJsonCount(2, 'discogs.options')
            ->assertJsonPath('discogs.selectedOwnedCopyId', null);
        $this->putJson("/api/albums/{$album->id}/musician-credits/discogs-source", [
            'sourceType' => 'owned_copy',
            'ownedCopyId' => $secondCopy->id,
        ])
            ->assertOk()
            ->assertJsonPath('discogs.selectedOwnedCopyId', $secondCopy->id)
            ->assertJsonPath('discogs.selectedReleaseId', 789)
            ->assertJsonPath('items.0.musician.name', 'Vinyl Edition Player')
            ->assertJsonPath('items.0.provider', 'discogs');

        $this->deleteJson("/api/albums/{$album->id}/musician-credits/discogs-source")
            ->assertOk()
            ->assertJsonPath('discogs.selectedOwnedCopyId', null)
            ->assertJsonCount(0, 'items');
    }

    /** @return array{Album, OwnedAlbumCopy} */
    private function createOwnedAlbum(string $title = 'Album'): array
    {
        $artistName = $title === 'Album' ? 'Artist' : 'Artist '.$title;
        $artist = Artist::create([
            'name' => $artistName,
            'sort_name' => $artistName,
            'browse_initial' => 'A',
        ]);
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/Music/'.str_replace(' ', '-', $title),
            'path_hash' => hash('sha256', 'd:/music/'.mb_strtolower($title)),
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => $title,
            'sort_title' => $title,
            'relative_path' => 'Artist/'.$title,
            'relative_path_hash' => hash('sha256', 'artist/'.mb_strtolower($title)),
            'original_release_year' => 2001,
        ]);
        $copy = $album->ownedCopies()->create([
            'is_physical' => true,
            'physical_format' => 'cd',
            'purchase_source' => 'Local store',
        ]);

        return [$album, $copy];
    }

    private function createTrack(Album $album, string $title, int $trackNumber): Track
    {
        $relativePath = "Artist/{$album->title}/{$trackNumber}.mp3";
        $mediaFile = MediaFile::create([
            'library_root_id' => $album->library_root_id,
            'album_id' => $album->id,
            'relative_path' => $relativePath,
            'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
            'raw_metadata' => [],
        ]);

        return Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $title,
            'sort_title' => $title,
            'disc_number' => 1,
            'track_number' => $trackNumber,
        ]);
    }
}
