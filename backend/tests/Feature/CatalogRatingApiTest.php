<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogRatingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_album_and_track_ratings_support_half_stars_and_can_be_cleared(): void
    {
        [$album, $track] = $this->createCatalog();

        $this->patchJson("/api/albums/{$album->id}/rating", ['rating' => 4.5])
            ->assertOk()
            ->assertExactJson(['id' => $album->id, 'rating' => 4.5]);
        $this->patchJson("/api/tracks/{$track->id}/rating", ['rating' => 2])
            ->assertOk()
            ->assertExactJson(['id' => $track->id, 'rating' => 2]);

        $this->getJson("/api/catalog/albums/{$album->id}")
            ->assertOk()
            ->assertJsonPath('rating', 4.5)
            ->assertJsonPath('tracks.0.rating', 2);
        $this->getJson("/api/catalog/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('rating', 2);

        $this->deleteJson("/api/albums/{$album->id}/rating")->assertNoContent();
        $this->deleteJson("/api/tracks/{$track->id}/rating")->assertNoContent();

        $this->assertDatabaseHas('albums', ['id' => $album->id, 'rating_half_steps' => null]);
        $this->assertDatabaseHas('tracks', ['id' => $track->id, 'rating_half_steps' => null]);
    }

    public function test_ratings_reject_values_outside_half_star_steps(): void
    {
        [$album, $track] = $this->createCatalog();

        $this->patchJson("/api/albums/{$album->id}/rating", ['rating' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rating');
        $this->patchJson("/api/tracks/{$track->id}/rating", ['rating' => 4.3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rating');
        $this->patchJson("/api/tracks/{$track->id}/rating", ['rating' => 5.5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rating');
    }

    /** @return array{Album, Track} */
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

        return [$album, $track];
    }
}
