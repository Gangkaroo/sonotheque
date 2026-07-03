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

class FavoritesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorite_tracks_and_albums_can_be_added_listed_and_removed(): void
    {
        [$artist, $album, $track] = $this->createCatalog();

        $this->getJson('/api/favorites')
            ->assertOk()
            ->assertJsonPath('tracks', [])
            ->assertJsonPath('albums', []);

        $this->postJson("/api/favorites/tracks/{$track->id}")
            ->assertCreated()
            ->assertJsonPath('trackId', $track->id);
        $this->postJson("/api/favorites/tracks/{$track->id}")->assertCreated();
        $this->postJson("/api/favorites/albums/{$album->id}")
            ->assertCreated()
            ->assertJsonPath('albumId', $album->id);

        $this->getJson('/api/favorites')
            ->assertOk()
            ->assertJsonPath('tracks.0', $track->id)
            ->assertJsonPath('albums.0', $album->id);

        $this->getJson('/api/favorites/tracks')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $track->id)
            ->assertJsonPath('items.0.title', 'Track')
            ->assertJsonPath('items.0.album.title', 'Album')
            ->assertJsonPath('items.0.artists.0.name', $artist->name);

        $this->getJson('/api/favorites/albums')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $album->id)
            ->assertJsonPath('items.0.title', 'Album')
            ->assertJsonPath('items.0.primaryArtist.name', $artist->name)
            ->assertJsonPath('items.0.trackCount', 1);

        $this->deleteJson("/api/favorites/tracks/{$track->id}")->assertNoContent();
        $this->deleteJson("/api/favorites/albums/{$album->id}")->assertNoContent();

        $this->getJson('/api/favorites')
            ->assertOk()
            ->assertJsonPath('tracks', [])
            ->assertJsonPath('albums', []);
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
}
