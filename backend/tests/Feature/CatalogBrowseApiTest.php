<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogBrowseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_browse_endpoints_return_flat_paginated_catalog_data(): void
    {
        [$artist, $album, $track, $genre] = $this->createCatalog();

        $this->getJson('/api/catalog/artists?initial=A&search=Artist')
            ->assertOk()
            ->assertJsonPath('items.0.id', $artist->id)
            ->assertJsonPath('items.0.albumCount', 1)
            ->assertJsonPath('total', 1);

        $this->getJson('/api/catalog/albums?search=Artist')
            ->assertOk()
            ->assertJsonPath('items.0.id', $album->id)
            ->assertJsonPath('items.0.primaryArtist.name', 'Artist')
            ->assertJsonPath('items.0.trackCount', 1);

        $this->getJson('/api/catalog/tracks?search=Track')
            ->assertOk()
            ->assertJsonPath('items.0.id', $track->id)
            ->assertJsonPath('items.0.album.title', 'Album')
            ->assertJsonPath('items.0.artists.0.name', 'Artist');

        $this->getJson('/api/catalog/genres?search=Rock')
            ->assertOk()
            ->assertJsonPath('items.0.id', $genre->id)
            ->assertJsonPath('items.0.trackCount', 1);
    }

    /** @return array{Artist, Album, Track, Genre} */
    private function createCatalog(): array
    {
        $artist = Artist::create([
            'name' => 'Artist',
            'sort_name' => 'Artist',
            'browse_initial' => 'A',
        ]);
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/Music',
            'path_hash' => hash('sha256', 'd:/music'),
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Album',
            'sort_title' => 'Album',
            'relative_path' => 'Artist/Album',
            'relative_path_hash' => hash('sha256', 'artist/album'),
            'original_release_year' => 2001,
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => 'Artist/Album/track.mp3',
            'relative_path_hash' => hash('sha256', 'artist/album/track.mp3'),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Track',
            'sort_title' => 'Track',
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);
        $genre = Genre::create(['name' => 'Rock']);
        $track->genres()->attach($genre);

        return [$artist, $album, $track, $genre];
    }
}
