<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_catalog_supports_search_initial_and_library_filters(): void
    {
        $catalog = $this->createCatalog();

        $this->getCatalog('/api/artists?search=alpha')
            ->assertOk()
            ->assertJsonCount(1, 'member')
            ->assertJsonPath('member.0.name', 'Alpha Artist');

        $this->getCatalog('/api/artists?initial=%23')
            ->assertOk()
            ->assertJsonCount(1, 'member')
            ->assertJsonPath('member.0.name', '2 Numeric');

        $this->getCatalog('/api/artists?library='.$catalog['secondLibrary']->id)
            ->assertOk()
            ->assertJsonCount(1, 'member')
            ->assertJsonPath('member.0.name', 'Beta Artist');
    }

    public function test_album_catalog_supports_title_artist_year_genre_and_library_filters(): void
    {
        $catalog = $this->createCatalog();

        $this->getCatalog('/api/albums?search=ALPHA')
            ->assertOk()
            ->assertJsonCount(1, 'member')
            ->assertJsonPath('member.0.title', 'Alpha Album')
            ->assertJsonMissingPath('member.0.relativePath')
            ->assertJsonMissingPath('member.0.relativePathHash')
            ->assertJsonMissingPath('member.0.metadata');

        $this->getCatalog('/api/albums?artist=alpha&year=1999')
            ->assertOk()
            ->assertJsonCount(1, 'member')
            ->assertJsonPath('member.0.originalReleaseYear', 1999);

        $this->getCatalog('/api/albums?genre='.$catalog['electronic']->id)
            ->assertOk()
            ->assertJsonCount(1, 'member')
            ->assertJsonPath('member.0.title', 'Alpha Album');

        $this->getCatalog('/api/albums?library='.$catalog['secondLibrary']->id)
            ->assertOk()
            ->assertJsonCount(1, 'member')
            ->assertJsonPath('member.0.title', 'Beta Album');

        $this->getCatalog('/api/albums?yearRange[gte]=2000&yearRange[lte]=2020')
            ->assertOk()
            ->assertJsonCount(2, 'member');
    }

    public function test_track_and_genre_catalogs_support_relationship_filters(): void
    {
        $catalog = $this->createCatalog();

        $this->getCatalog('/api/tracks?genre='.$catalog['electronic']->id)
            ->assertOk()
            ->assertJsonCount(1, 'member')
            ->assertJsonPath('member.0.title', 'Alpha Track');

        $this->getCatalog('/api/genres?library='.$catalog['secondLibrary']->id)
            ->assertOk()
            ->assertJsonCount(1, 'member')
            ->assertJsonPath('member.0.name', 'Rock');
    }

    public function test_catalog_resources_are_read_only_and_reject_unknown_filters(): void
    {
        $this->postJson('/api/artists', ['name' => 'Created through API'])
            ->assertMethodNotAllowed();

        $this->getCatalog('/api/albums?unknown=value')
            ->assertBadRequest();
    }

    public function test_library_catalog_does_not_expose_physical_roots(): void
    {
        $this->createCatalog();

        $this->getCatalog('/api/libraries')
            ->assertOk()
            ->assertJsonCount(2, 'member')
            ->assertJsonMissingPath('member.0.roots')
            ->assertJsonMissing(['path' => 'D:\\Music']);
    }

    public function test_openapi_documents_catalog_filters(): void
    {
        $document = $this->get('/api/docs.jsonopenapi', [
            'Accept' => 'application/vnd.openapi+json',
        ])->assertOk()->json();

        $albumParameters = collect($document['paths']['/api/albums']['get']['parameters'])
            ->pluck('name')
            ->all();

        $this->assertContains('search', $albumParameters);
        $this->assertContains('artist', $albumParameters);
        $this->assertContains('library', $albumParameters);
        $this->assertContains('year', $albumParameters);
        $this->assertContains('genre', $albumParameters);
    }

    private function getCatalog(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => 'application/ld+json']);
    }

    /**
     * @return array{
     *     firstLibrary: Library,
     *     secondLibrary: Library,
     *     electronic: Genre
     * }
     */
    private function createCatalog(): array
    {
        $firstLibrary = Library::create(['name' => 'First Library']);
        $firstRoot = $firstLibrary->roots()->create([
            'name' => 'First Root',
            'path' => 'D:\\Music',
            'path_hash' => hash('sha256', 'd:\\music'),
        ]);
        $secondLibrary = Library::create(['name' => 'Second Library']);
        $secondRoot = $secondLibrary->roots()->create([
            'name' => 'Second Root',
            'path' => 'E:\\Music',
            'path_hash' => hash('sha256', 'e:\\music'),
        ]);

        $alpha = Artist::create([
            'name' => 'Alpha Artist',
            'sort_name' => 'Alpha Artist',
            'browse_initial' => 'A',
        ]);
        $numeric = Artist::create([
            'name' => '2 Numeric',
            'sort_name' => '2 Numeric',
            'browse_initial' => '#',
        ]);
        $beta = Artist::create([
            'name' => 'Beta Artist',
            'sort_name' => 'Beta Artist',
            'browse_initial' => 'B',
        ]);

        $alphaAlbum = $this->createAlbumWithTrack(
            $firstRoot->id,
            $alpha,
            'Alpha Album',
            'Alpha Track',
            1999,
        );
        $numericAlbum = $this->createAlbumWithTrack(
            $firstRoot->id,
            $numeric,
            'Number Album',
            'Number Track',
            2000,
        );
        $betaAlbum = $this->createAlbumWithTrack(
            $secondRoot->id,
            $beta,
            'Beta Album',
            'Beta Track',
            2020,
        );

        $electronic = Genre::create(['name' => 'Electronic']);
        $rock = Genre::create(['name' => 'Rock']);
        $alphaAlbum->tracks->first()->genres()->attach($electronic);
        $betaAlbum->tracks->first()->genres()->attach($rock);

        return [
            'firstLibrary' => $firstLibrary,
            'secondLibrary' => $secondLibrary,
            'electronic' => $electronic,
        ];
    }

    private function createAlbumWithTrack(
        int $libraryRootId,
        Artist $artist,
        string $albumTitle,
        string $trackTitle,
        int $releaseYear,
    ): Album {
        $relativeAlbumPath = $artist->name.'\\'.$albumTitle;
        $album = Album::create([
            'library_root_id' => $libraryRootId,
            'primary_artist_id' => $artist->id,
            'title' => $albumTitle,
            'sort_title' => $albumTitle,
            'relative_path' => $relativeAlbumPath,
            'relative_path_hash' => hash('sha256', strtolower($relativeAlbumPath)),
            'original_release_year' => $releaseYear,
        ]);
        $relativeTrackPath = $relativeAlbumPath.'\\'.$trackTitle.'.flac';
        $mediaFile = MediaFile::create([
            'library_root_id' => $libraryRootId,
            'album_id' => $album->id,
            'relative_path' => $relativeTrackPath,
            'relative_path_hash' => hash('sha256', strtolower($relativeTrackPath)),
            'file_size' => 1000,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $trackTitle,
            'sort_title' => $trackTitle,
            'track_number' => 1,
            'disc_number' => 1,
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        return $album->load('tracks');
    }
}
