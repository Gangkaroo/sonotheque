<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use App\Models\TrackPlayStatistic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_catalog_counts(): void
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
        TrackPlayStatistic::create([
            'track_id' => $track->id,
            'play_count' => 2,
            'first_played_at' => now(),
            'last_played_at' => now(),
        ]);
        Artist::create([
            'name' => 'Unused',
            'sort_name' => 'Unused',
            'browse_initial' => 'U',
        ]);
        $track->genres()->attach(Genre::create(['name' => 'Rock']));
        Genre::create(['name' => 'Unused']);

        $this->getJson('/api/dashboard-metrics')
            ->assertOk()
            ->assertExactJson([
                'artists' => 1,
                'albums' => 1,
                'tracks' => 1,
                'genres' => 1,
                'playedAlbums' => 1,
                'playedTracks' => 1,
            ]);
    }
}
