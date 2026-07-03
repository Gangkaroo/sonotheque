<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPaginationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_collection_accepts_its_generated_next_page_link(): void
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

        for ($index = 1; $index <= 51; $index++) {
            $relativePath = "Artist/Album/track-{$index}.mp3";
            $mediaFile = MediaFile::create([
                'library_root_id' => $root->id,
                'album_id' => $album->id,
                'relative_path' => $relativePath,
                'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
                'file_size' => 1,
                'modified_at' => now(),
                'last_seen_at' => now(),
            ]);
            Track::create([
                'album_id' => $album->id,
                'media_file_id' => $mediaFile->id,
                'title' => "Track {$index}",
                'sort_title' => "Track {$index}",
                'track_number' => $index,
                'disc_number' => 1,
            ]);
        }

        $firstPage = $this->get('/api/tracks', ['Accept' => 'application/ld+json'])
            ->assertOk()
            ->assertJsonCount(50, 'member');

        $next = $firstPage->json('view.next');

        $this->assertSame('/api/tracks?page=2', $next);
        $this->get($next, ['Accept' => 'application/ld+json'])
            ->assertOk()
            ->assertJsonCount(1, 'member');
    }
}
