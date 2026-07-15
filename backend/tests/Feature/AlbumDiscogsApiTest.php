<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\OwnedAlbumCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlbumDiscogsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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
        Http::fake(['api.discogs.com/database/search*' => Http::response([
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
                    'thumb' => 'https://i.discogs.com/example.jpg',
                    'uri' => '/release/456-Artist-Album',
                    'user_data' => ['in_collection' => true],
                ],
                ['id' => 79, 'type' => 'master', 'title' => 'Artist - Album'],
            ],
        ])]);

        $this->getJson("/api/albums/{$album->id}/discogs/candidates?artist=Artist&title=Album&year=2001&format=CD")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.releaseId', 456)
            ->assertJsonPath('items.0.masterId', 78)
            ->assertJsonPath('items.0.catalogNumber', 'EX-123')
            ->assertJsonPath('items.0.inCollection', true)
            ->assertJsonPath('items.0.webUrl', 'https://www.discogs.com/release/456-Artist-Album');

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://api.discogs.com/database/search?',
        ) && $request['type'] === 'release'
            && $request['artist'] === 'Artist'
            && $request['release_title'] === 'Album'
            && $request['year'] === '2001'
            && $request['format'] === 'CD');
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

    public function test_it_does_not_link_a_copy_from_another_album(): void
    {
        [$album] = $this->createOwnedAlbum();
        [, $otherCopy] = $this->createOwnedAlbum('Other Album');

        $this->putJson("/api/albums/{$album->id}/owned-copies/{$otherCopy->id}/discogs", [
            'releaseId' => 456,
        ])->assertNotFound();

        Http::assertNothingSent();
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
}
