<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\FavoriteAlbum;
use App\Models\FavoriteTrack;
use App\Models\Genre;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\TrackPlayEvent;
use App\Models\TrackPlayStatistic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryRootScopeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_reads_are_restricted_to_the_selected_library_root(): void
    {
        $library = Library::create(['name' => 'Test library']);
        [$firstRoot, $firstAlbum, $firstTrack] = $this->createCatalog($library, 'First', 'D:/Music');
        [, $secondAlbum, $secondTrack] = $this->createCatalog($library, 'Second', 'E:/Music');

        $query = '?libraryRoot='.$firstRoot->id;

        $this->getJson('/api/catalog/artists'.$query)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.name', 'First Artist');
        $this->getJson('/api/catalog/albums'.$query)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $firstAlbum->id);
        $this->getJson('/api/catalog/tracks'.$query)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $firstTrack->id);
        $this->getJson('/api/catalog/genres'.$query)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.name', 'First Genre');
        $this->getJson('/api/dashboard-metrics'.$query)
            ->assertOk()
            ->assertJsonPath('artists', 1)
            ->assertJsonPath('albums', 1)
            ->assertJsonPath('tracks', 1)
            ->assertJsonPath('genres', 1)
            ->assertJsonPath('playedTracks', 1);
        $this->getJson('/api/catalog/playback/albums/random'.$query)
            ->assertOk()
            ->assertJsonPath('id', $firstAlbum->id);
        $this->getJson('/api/catalog/playback/tracks/random'.$query)
            ->assertOk()
            ->assertJsonPath('id', $firstTrack->id);

        $this->getJson("/api/catalog/albums/{$secondAlbum->id}{$query}")->assertNotFound();
        $this->getJson("/api/catalog/tracks/{$secondTrack->id}{$query}")->assertNotFound();
    }

    public function test_favorites_statistics_and_playlist_contents_respect_the_scope(): void
    {
        $library = Library::create(['name' => 'Test library']);
        [$firstRoot, $firstAlbum, $firstTrack] = $this->createCatalog($library, 'First', 'D:/Music');
        [, $secondAlbum, $secondTrack] = $this->createCatalog($library, 'Second', 'E:/Music');
        FavoriteAlbum::create(['album_id' => $firstAlbum->id]);
        FavoriteAlbum::create(['album_id' => $secondAlbum->id]);
        FavoriteTrack::create(['track_id' => $firstTrack->id]);
        FavoriteTrack::create(['track_id' => $secondTrack->id]);
        $playlist = Playlist::create(['name' => 'Across disks']);
        $playlist->items()->create(['track_id' => $firstTrack->id, 'position' => 0]);
        $playlist->items()->create(['track_id' => $secondTrack->id, 'position' => 1]);

        $query = '?libraryRoot='.$firstRoot->id;

        $this->getJson('/api/favorites'.$query)
            ->assertOk()
            ->assertJsonPath('tracks', [$firstTrack->id])
            ->assertJsonPath('albums', [$firstAlbum->id]);
        $this->getJson('/api/favorites/tracks'.$query)->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/favorites/albums'.$query)->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/statistics/recent-plays'.$query)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.track.id', $firstTrack->id);
        $this->getJson('/api/statistics/most-played-tracks'.$query)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $firstTrack->id);
        $this->getJson('/api/statistics/most-played-albums'.$query)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $firstAlbum->id);
        $this->getJson('/api/playlists'.$query)
            ->assertOk()
            ->assertJsonPath('items.0.trackCount', 1);
        $this->getJson("/api/playlists/{$playlist->id}{$query}")
            ->assertOk()
            ->assertJsonPath('trackCount', 1)
            ->assertJsonPath('items.0.track.id', $firstTrack->id)
            ->assertJsonCount(1, 'items');
        $this->getJson(
            "/api/playlists/memberships{$query}&trackIds[]={$firstTrack->id}&trackIds[]={$secondTrack->id}",
        )
            ->assertOk()
            ->assertJsonPath('items.0.playlists.0.id', $playlist->id)
            ->assertJsonPath('items.1.playlists', []);
    }

    public function test_disabled_or_unknown_library_roots_are_rejected(): void
    {
        $library = Library::create(['name' => 'Test library']);
        [$root] = $this->createCatalog($library, 'Disabled', 'D:/Music');
        $root->update(['enabled' => false]);

        $this->getJson('/api/catalog/albums?libraryRoot='.$root->id)->assertUnprocessable();
        $this->getJson('/api/catalog/albums?libraryRoot=999999')->assertUnprocessable();
        $this->getJson('/api/catalog/albums')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/dashboard-metrics')->assertOk()->assertJsonPath('tracks', 0);
    }

    /** @return array{LibraryRoot, Album, Track} */
    private function createCatalog(Library $library, string $name, string $path): array
    {
        $artist = Artist::create([
            'name' => $name.' Artist',
            'sort_name' => $name.' Artist',
            'browse_initial' => strtoupper($name[0]),
        ]);
        $root = $library->roots()->create([
            'name' => $name,
            'path' => $path,
            'path_hash' => hash('sha256', strtolower($path)),
            'enabled' => true,
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => $name.' Album',
            'sort_title' => $name.' Album',
            'relative_path' => $name.' Artist/'.$name.' Album',
            'relative_path_hash' => hash('sha256', strtolower($name).'/album'),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => $name.' Artist/'.$name.' Album/track.mp3',
            'relative_path_hash' => hash('sha256', strtolower($name).'/track.mp3'),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $name.' Track',
            'sort_title' => $name.' Track',
            'duration_ms' => 180000,
            'disc_number' => 1,
            'track_number' => 1,
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);
        $genre = Genre::create(['name' => $name.' Genre']);
        $track->genres()->attach($genre);
        TrackPlayStatistic::create([
            'track_id' => $track->id,
            'play_count' => 1,
            'first_played_at' => now(),
            'last_played_at' => now(),
        ]);
        TrackPlayEvent::create([
            'track_id' => $track->id,
            'media_file_id' => $mediaFile->id,
            'played_at' => now(),
            'listened_ms' => 180000,
            'duration_ms' => 180000,
            'counted' => true,
            'source' => 'web',
        ]);

        return [$root, $album, $track];
    }
}
