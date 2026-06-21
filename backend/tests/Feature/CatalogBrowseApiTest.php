<?php

namespace Tests\Feature;

use App\Enums\ArtworkSource;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Artwork;
use App\Models\Genre;
use App\Models\Library;
use App\Models\LibraryRoot;
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
        $secondAlbum = Album::create([
            'library_root_id' => $album->library_root_id,
            'primary_artist_id' => $artist->id,
            'title' => 'Filtered Album',
            'sort_title' => 'Filtered Album',
            'relative_path' => 'Artist/Filtered Album',
            'relative_path_hash' => hash('sha256', 'artist/filtered album'),
            'original_release_year' => 1999,
        ]);
        $this->createTrackForAlbum($album->libraryRoot, $secondAlbum);

        $this->getJson('/api/catalog/artists?initial=A&search=Artist')
            ->assertOk()
            ->assertJsonPath('items.0.id', $artist->id)
            ->assertJsonPath('items.0.albumCount', 2)
            ->assertJsonPath('total', 1);

        $this->getJson('/api/catalog/albums?search=Artist')
            ->assertOk()
            ->assertJsonPath('items.0.id', $album->id)
            ->assertJsonPath('items.0.primaryArtist.name', 'Artist')
            ->assertJsonPath('items.0.trackCount', 1);

        $this->getJson('/api/catalog/albums?year=2001')
            ->assertOk()
            ->assertJsonPath('items.0.id', $album->id)
            ->assertJsonPath('total', 1);

        $this->getJson('/api/catalog/albums?year=1999')
            ->assertOk()
            ->assertJsonPath('items.0.id', $secondAlbum->id)
            ->assertJsonPath('total', 1);

        $this->getJson('/api/catalog/tracks?search=Track')
            ->assertOk()
            ->assertJsonPath('items.0.id', $track->id)
            ->assertJsonPath('items.0.streamUrl', "/api/tracks/{$track->id}/stream")
            ->assertJsonPath('items.0.album.title', 'Album')
            ->assertJsonPath('items.0.artists.0.name', 'Artist');

        $this->getJson('/api/catalog/tracks?search=Artist')
            ->assertOk()
            ->assertJsonPath('items.0.id', $track->id)
            ->assertJsonPath('total', 1);

        $this->getJson('/api/catalog/genres?search=Rock')
            ->assertOk()
            ->assertJsonPath('items.0.id', $genre->id)
            ->assertJsonPath('items.0.trackCount', 1);
    }

    public function test_album_detail_returns_album_metadata_and_tracks(): void
    {
        [$artist, $album, $track] = $this->createCatalog();
        $artwork = Artwork::create([
            'source_type' => ArtworkSource::Folder,
            'source_relative_path' => 'Cover/Front.jpg',
            'cache_path' => 'originals/example.jpg',
            'thumbnail_path' => 'thumbnails/example.webp',
            'mime_type' => 'image/jpeg',
            'width' => 1200,
            'height' => 1200,
            'checksum' => hash('sha256', 'example artwork'),
        ]);
        $album->update(['artwork_id' => $artwork->id]);

        $this->getJson("/api/catalog/albums/{$album->id}")
            ->assertOk()
            ->assertJsonPath('id', $album->id)
            ->assertJsonPath('title', 'Album')
            ->assertJsonPath('primaryArtist.id', $artist->id)
            ->assertJsonPath('primaryArtist.name', 'Artist')
            ->assertJsonPath('trackCount', 1)
            ->assertJsonPath('artworkThumbnailUrl', "/api/artwork/{$artwork->id}/thumbnail")
            ->assertJsonPath('artworkUrl', "/api/artwork/{$artwork->id}/original")
            ->assertJsonPath('artworkWidth', 1200)
            ->assertJsonPath('artworkHeight', 1200)
            ->assertJsonPath('genres.0.id', Genre::where('name', 'Rock')->value('id'))
            ->assertJsonPath('genres.0.name', 'Rock')
            ->assertJsonPath('tracks.0.id', $track->id)
            ->assertJsonPath('tracks.0.streamUrl', "/api/tracks/{$track->id}/stream")
            ->assertJsonPath('tracks.0.album.title', 'Album')
            ->assertJsonPath('tracks.0.artists.0.name', 'Artist');
    }

    public function test_albums_are_sorted_by_primary_artist_and_can_filter_by_artist_initial(): void
    {
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/Music',
            'path_hash' => hash('sha256', 'd:/music'),
        ]);
        $alphaArtist = Artist::create([
            'name' => 'Alpha Artist',
            'sort_name' => 'Alpha Artist',
            'browse_initial' => 'A',
        ]);
        $betaArtist = Artist::create([
            'name' => 'Beta Artist',
            'sort_name' => 'Beta Artist',
            'browse_initial' => 'B',
        ]);
        $zedArtist = Artist::create([
            'name' => 'Zed Artist',
            'sort_name' => 'Zed Artist',
            'browse_initial' => 'Z',
        ]);

        $alphaSecondAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $alphaArtist->id,
            'title' => 'Beta Album',
            'sort_title' => 'Beta Album',
            'relative_path' => 'Alpha Artist/Beta Album',
            'relative_path_hash' => hash('sha256', 'alpha artist/beta album'),
        ]);
        $zedAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $zedArtist->id,
            'title' => 'Aardvark Album',
            'sort_title' => 'Aardvark Album',
            'relative_path' => 'Zed Artist/Aardvark Album',
            'relative_path_hash' => hash('sha256', 'zed artist/aardvark album'),
        ]);
        $alphaFirstAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $alphaArtist->id,
            'title' => 'Alpha Album',
            'sort_title' => 'Alpha Album',
            'relative_path' => 'Alpha Artist/Alpha Album',
            'relative_path_hash' => hash('sha256', 'alpha artist/alpha album'),
        ]);
        $betaAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $betaArtist->id,
            'title' => 'Beta Artist Album',
            'sort_title' => 'Beta Artist Album',
            'relative_path' => 'Beta Artist/Beta Artist Album',
            'relative_path_hash' => hash('sha256', 'beta artist/beta artist album'),
        ]);
        Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $zedArtist->id,
            'title' => 'Orphaned Folder',
            'sort_title' => 'Orphaned Folder',
            'relative_path' => 'Zed Artist/Orphaned Folder',
            'relative_path_hash' => hash('sha256', 'zed artist/orphaned folder'),
        ]);

        $this->createTrackForAlbum($root, $alphaSecondAlbum);
        $this->createTrackForAlbum($root, $zedAlbum);
        $this->createTrackForAlbum($root, $alphaFirstAlbum);
        $this->createTrackForAlbum($root, $betaAlbum);

        $this->getJson('/api/catalog/albums')
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('items.0.id', $alphaFirstAlbum->id)
            ->assertJsonPath('items.1.id', $alphaSecondAlbum->id)
            ->assertJsonPath('items.2.id', $betaAlbum->id)
            ->assertJsonPath('items.3.id', $zedAlbum->id);

        $this->getJson('/api/catalog/albums?initial=A')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('items.0.id', $alphaFirstAlbum->id)
            ->assertJsonPath('items.1.id', $alphaSecondAlbum->id);
    }

    public function test_playback_endpoints_return_random_and_sequential_targets(): void
    {
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/Music',
            'path_hash' => hash('sha256', 'd:/music'),
        ]);
        $alphaArtist = Artist::create(['name' => 'Alpha Artist', 'sort_name' => 'Alpha Artist', 'browse_initial' => 'A']);
        $betaArtist = Artist::create(['name' => 'Beta Artist', 'sort_name' => 'Beta Artist', 'browse_initial' => 'B']);
        $alphaAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $alphaArtist->id,
            'title' => 'Alpha Album',
            'sort_title' => 'Alpha Album',
            'relative_path' => 'Alpha Artist/Alpha Album',
            'relative_path_hash' => hash('sha256', 'alpha artist/alpha album'),
        ]);
        $betaAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $betaArtist->id,
            'title' => 'Beta Album',
            'sort_title' => 'Beta Album',
            'relative_path' => 'Beta Artist/Beta Album',
            'relative_path_hash' => hash('sha256', 'beta artist/beta album'),
        ]);
        $alphaTrack = $this->createTrackForAlbum($root, $alphaAlbum);
        $betaTrack = $this->createTrackForAlbum($root, $betaAlbum);

        $this->getJson("/api/catalog/playback/albums/{$alphaAlbum->id}/next")
            ->assertOk()
            ->assertJsonPath('id', $betaAlbum->id)
            ->assertJsonPath('tracks.0.album.id', $betaAlbum->id);

        $this->getJson("/api/catalog/playback/albums/{$betaAlbum->id}/next")
            ->assertOk()
            ->assertJsonPath('id', $alphaAlbum->id);

        $this->getJson("/api/catalog/playback/albums/random?exclude={$alphaAlbum->id}")
            ->assertOk()
            ->assertJsonPath('id', $betaAlbum->id);

        $this->getJson("/api/catalog/playback/tracks/{$alphaTrack->id}/next")
            ->assertOk()
            ->assertJsonPath('id', $betaTrack->id)
            ->assertJsonPath('album.id', $betaAlbum->id);

        $this->getJson("/api/catalog/playback/tracks/random?exclude={$alphaTrack->id}")
            ->assertOk()
            ->assertJsonPath('id', $betaTrack->id);
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
            'duration_ms' => 123000,
            'disc_number' => 1,
            'track_number' => 1,
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);
        $genre = Genre::create(['name' => 'Rock']);
        $track->genres()->attach($genre);

        return [$artist, $album, $track, $genre];
    }

    private function createTrackForAlbum(LibraryRoot $root, Album $album): Track
    {
        $relativePath = $album->relative_path.'/track.mp3';
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => $relativePath,
            'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);

        return Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $album->title.' Track',
            'sort_title' => $album->title.' Track',
        ]);
    }
}
