<?php

namespace Tests\Feature;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\Artist;
use App\Models\FavoriteTrack;
use App\Models\Genre;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\TrackPlayStatistic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrashApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_trash_lists_only_missing_tracks_and_supports_search_and_root_scope(): void
    {
        $firstRoot = $this->createRoot('Archive', 'D:/Archive');
        $secondRoot = $this->createRoot('Recent', 'E:/Recent');
        $missing = $this->createTrack($firstRoot, 'Bjoerk', 'Debut', 'Human Behaviour', MediaFileStatus::Missing);
        $this->createTrack($firstRoot, 'Bjoerk', 'Post', 'Army of Me', MediaFileStatus::Available);
        $this->createTrack($secondRoot, 'Portishead', 'Dummy', 'Roads', MediaFileStatus::Missing);

        $this->getJson('/api/trash/tracks')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('items.0.libraryRoot.name', 'Recent')
            ->assertJsonPath('items.1.id', $missing->id)
            ->assertJsonPath('items.1.relativePath', 'Bjoerk/Debut/Human Behaviour.mp3');

        $this->getJson("/api/trash/tracks?libraryRoot={$firstRoot->id}&search=Bjoerk%20Behaviour")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $missing->id)
            ->assertJsonPath('items.0.artists.0.name', 'Bjoerk');
    }

    public function test_only_missing_tracks_can_be_permanently_deleted(): void
    {
        $root = $this->createRoot('Archive', 'D:/Archive');
        $available = $this->createTrack(
            $root,
            'Bjoerk',
            'Debut',
            'Human Behaviour',
            MediaFileStatus::Available,
        );

        $this->deleteJson("/api/trash/tracks/{$available->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Only unavailable tracks can be permanently deleted.');

        $this->assertDatabaseHas(Track::class, ['id' => $available->id]);
        $this->assertDatabaseHas(MediaFile::class, ['id' => $available->media_file_id]);
    }

    public function test_permanent_deletion_removes_personal_references_and_normalizes_playlists(): void
    {
        $root = $this->createRoot('Archive', 'D:/Archive');
        $missing = $this->createTrack(
            $root,
            'Bjoerk',
            'Debut',
            'Human Behaviour',
            MediaFileStatus::Missing,
        );
        $retained = $this->createTrack(
            $root,
            'Bjoerk',
            'Post',
            'Army of Me',
            MediaFileStatus::Available,
        );
        $playlist = Playlist::create(['name' => 'Bjoerk']);
        $playlist->items()->create(['track_id' => $missing->id, 'position' => 0]);
        $retainedItem = $playlist->items()->create(['track_id' => $retained->id, 'position' => 1]);
        FavoriteTrack::create(['track_id' => $missing->id]);
        TrackPlayStatistic::create([
            'track_id' => $missing->id,
            'play_count' => 4,
            'first_played_at' => now()->subDay(),
            'last_played_at' => now(),
        ]);
        $missingAlbumId = $missing->album_id;
        $missingMediaFileId = $missing->media_file_id;

        $this->deleteJson('/api/trash/tracks', ['trackIds' => [$missing->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertDatabaseMissing(Track::class, ['id' => $missing->id]);
        $this->assertDatabaseMissing(MediaFile::class, ['id' => $missingMediaFileId]);
        $this->assertDatabaseMissing(Album::class, ['id' => $missingAlbumId]);
        $this->assertDatabaseMissing(FavoriteTrack::class, ['track_id' => $missing->id]);
        $this->assertDatabaseMissing(TrackPlayStatistic::class, ['track_id' => $missing->id]);
        $this->assertDatabaseHas('playlist_items', [
            'id' => $retainedItem->id,
            'position' => 0,
        ]);
        $this->assertDatabaseHas(Track::class, ['id' => $retained->id]);
        $this->assertDatabaseHas(Artist::class, ['name' => 'Bjoerk']);
        $this->assertDatabaseHas(Genre::class, ['name' => 'Alternative']);
    }

    private function createRoot(string $name, string $path): LibraryRoot
    {
        $library = Library::query()->firstOrCreate(['name' => 'Test']);

        return $library->roots()->create([
            'name' => $name,
            'path' => $path,
            'path_hash' => hash('sha256', mb_strtolower($path)),
        ]);
    }

    private function createTrack(
        LibraryRoot $root,
        string $artistName,
        string $albumTitle,
        string $trackTitle,
        MediaFileStatus $status,
    ): Track {
        $artist = Artist::query()->firstOrCreate(
            ['name' => $artistName],
            [
                'sort_name' => $artistName,
                'browse_initial' => mb_strtoupper(mb_substr($artistName, 0, 1)),
            ],
        );
        $albumRelativePath = "{$artistName}/{$albumTitle}";
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => $albumTitle,
            'sort_title' => $albumTitle,
            'relative_path' => $albumRelativePath,
            'relative_path_hash' => hash('sha256', mb_strtolower($albumRelativePath)),
        ]);
        $relativePath = "{$albumRelativePath}/{$trackTitle}.mp3";
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => $relativePath,
            'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
            'file_size' => 100,
            'modified_at' => now(),
            'last_seen_at' => now(),
            'status' => $status,
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $trackTitle,
            'sort_title' => $trackTitle,
            'duration_ms' => 180000,
            'disc_number' => 1,
            'track_number' => 1,
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);
        $genre = Genre::query()->firstOrCreate(['name' => 'Alternative']);
        $track->genres()->attach($genre);

        return $track;
    }
}
