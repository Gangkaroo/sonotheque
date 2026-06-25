<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistFolder;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_playlist_folders_and_playlists_can_be_managed(): void
    {
        $this->postJson('/api/playlist-folders', ['name' => 'Road trips'])
            ->assertCreated()
            ->assertJsonPath('name', 'Road trips')
            ->assertJsonPath('parent', null)
            ->assertJsonPath('playlistCount', 0);

        $folder = PlaylistFolder::firstOrFail();

        $this->postJson('/api/playlist-folders', [
            'name' => 'Road trips',
            'parentId' => $folder->id,
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Road trips')
            ->assertJsonPath('parent.id', $folder->id);

        $this->postJson('/api/playlists', [
            'name' => 'Late night',
            'description' => 'Quiet favorites',
            'folderId' => $folder->id,
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Late night')
            ->assertJsonPath('description', 'Quiet favorites')
            ->assertJsonPath('folder.id', $folder->id)
            ->assertJsonPath('trackCount', 0);

        $playlist = Playlist::firstOrFail();

        $this->getJson('/api/playlist-folders')
            ->assertOk()
            ->assertJsonPath('items.0.name', 'Road trips')
            ->assertJsonPath('items.0.playlistCount', 1);

        $childFolder = PlaylistFolder::where('parent_id', $folder->id)->firstOrFail();
        $this->deleteJson("/api/playlist-folders/{$childFolder->id}")->assertNoContent();

        $this->patchJson("/api/playlists/{$playlist->id}", [
            'name' => 'Late night edits',
            'folderId' => null,
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Late night edits')
            ->assertJsonPath('folder', null);

        $this->patchJson("/api/playlist-folders/{$folder->id}", ['name' => 'Long drives'])
            ->assertOk()
            ->assertJsonPath('name', 'Long drives');

        $this->getJson('/api/playlists?withoutFolder=1')
            ->assertOk()
            ->assertJsonPath('items.0.name', 'Late night edits');

        $this->deleteJson("/api/playlists/{$playlist->id}")->assertNoContent();
        $this->deleteJson("/api/playlist-folders/{$folder->id}")->assertNoContent();

        $this->getJson('/api/playlists')->assertOk()->assertJsonPath('items', []);
        $this->getJson('/api/playlist-folders')->assertOk()->assertJsonPath('items', []);
    }

    public function test_playlist_tracks_can_be_added_removed_and_reordered(): void
    {
        [, , $firstTrack, , $secondTrack] = $this->createCatalog();
        $playlist = Playlist::create(['name' => 'Favorites for later']);

        $this->postJson("/api/playlists/{$playlist->id}/tracks/{$firstTrack->id}")
            ->assertCreated()
            ->assertJsonPath('position', 0)
            ->assertJsonPath('track.title', 'First track');
        $this->postJson("/api/playlists/{$playlist->id}/tracks/{$secondTrack->id}")
            ->assertCreated()
            ->assertJsonPath('position', 1)
            ->assertJsonPath('track.title', 'Second track');

        $playlistItems = $playlist->items()->orderBy('position')->pluck('id')->values();

        $this->patchJson("/api/playlists/{$playlist->id}/items/reorder", [
            'items' => [$playlistItems[1], $playlistItems[0]],
        ])
            ->assertOk()
            ->assertJsonPath('items.0.track.title', 'Second track')
            ->assertJsonPath('items.0.position', 0)
            ->assertJsonPath('items.1.track.title', 'First track')
            ->assertJsonPath('items.1.position', 1);

        $this->deleteJson("/api/playlists/{$playlist->id}/items/{$playlistItems[1]}")->assertNoContent();

        $this->getJson("/api/playlists/{$playlist->id}")
            ->assertOk()
            ->assertJsonPath('trackCount', 1)
            ->assertJsonPath('items.0.track.title', 'First track')
            ->assertJsonPath('items.0.position', 0);
    }

    public function test_multiple_tracks_can_be_added_to_a_playlist_in_one_request(): void
    {
        [, , $firstTrack, , $secondTrack] = $this->createCatalog();
        $playlist = Playlist::create(['name' => 'Bulk additions']);

        $this->postJson("/api/playlists/{$playlist->id}/tracks", [
            'trackIds' => [$firstTrack->id, $secondTrack->id],
        ])
            ->assertCreated()
            ->assertJsonPath('items.0.position', 0)
            ->assertJsonPath('items.0.track.title', 'First track')
            ->assertJsonPath('items.1.position', 1)
            ->assertJsonPath('items.1.track.title', 'Second track');

        $this->assertSame(
            [$firstTrack->id, $secondTrack->id],
            $playlist->items()->orderBy('position')->pluck('track_id')->all(),
        );
    }

    public function test_multiple_playlist_items_can_be_removed_in_one_request(): void
    {
        [, , $firstTrack, , $secondTrack] = $this->createCatalog();
        $playlist = Playlist::create(['name' => 'Trim me']);
        $this->postJson("/api/playlists/{$playlist->id}/tracks", [
            'trackIds' => [$firstTrack->id, $secondTrack->id, $firstTrack->id],
        ])->assertCreated();

        $items = $playlist->items()->orderBy('position')->pluck('id')->values();

        $this->deleteJson("/api/playlists/{$playlist->id}/items", [
            'items' => [$items[0], $items[1]],
        ])
            ->assertOk()
            ->assertJsonPath('trackCount', 1)
            ->assertJsonPath('items.0.position', 0)
            ->assertJsonPath('items.0.track.title', 'First track');

        $this->assertSame([$items[2]], $playlist->items()->orderBy('position')->pluck('id')->all());
    }

    public function test_reorder_requires_all_playlist_items(): void
    {
        [, , $firstTrack, , $secondTrack] = $this->createCatalog();
        $playlist = Playlist::create(['name' => 'Broken order']);
        $this->postJson("/api/playlists/{$playlist->id}/tracks/{$firstTrack->id}")->assertCreated();
        $this->postJson("/api/playlists/{$playlist->id}/tracks/{$secondTrack->id}")->assertCreated();

        $firstItem = $playlist->items()->orderBy('position')->firstOrFail();

        $this->patchJson("/api/playlists/{$playlist->id}/items/reorder", [
            'items' => [$firstItem->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    }

    /** @return array{Artist, Album, Track, Genre, Track} */
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
        $genre = Genre::create(['name' => 'Rock']);
        $tracks = collect(['First track', 'Second track'])->map(function (string $title, int $index) use ($root, $album, $artist, $genre) {
            $mediaFile = MediaFile::create([
                'library_root_id' => $root->id,
                'album_id' => $album->id,
                'relative_path' => "Artist/Album/track-{$index}.mp3",
                'relative_path_hash' => hash('sha256', "artist/album/track-{$index}.mp3"),
                'file_size' => 1,
                'modified_at' => now(),
                'last_seen_at' => now(),
            ]);
            $track = Track::create([
                'album_id' => $album->id,
                'media_file_id' => $mediaFile->id,
                'title' => $title,
                'sort_title' => $title,
                'duration_ms' => 123000,
                'disc_number' => 1,
                'track_number' => $index + 1,
            ]);
            $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);
            $track->genres()->attach($genre);

            return $track;
        });

        return [$artist, $album, $tracks[0], $genre, $tracks[1]];
    }
}
