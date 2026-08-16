<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\AlbumMusicianCredit;
use App\Models\Artist;
use App\Models\Library;
use App\Models\ManualAlbumMusicianCredit;
use App\Models\MediaFile;
use App\Models\Musician;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MusicianCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_musician_details_and_album_cards_include_effective_credit_context(): void
    {
        $library = Library::create(['name' => 'Test']);
        $root = $library->roots()->create([
            'name' => 'Archive',
            'path' => 'D:/Archive',
            'path_hash' => hash('sha256', 'd:/archive'),
        ]);
        $artist = Artist::create([
            'name' => 'Example Artist',
            'sort_name' => 'Example Artist',
            'browse_initial' => 'E',
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Example Album',
            'sort_title' => 'Example Album',
            'relative_path' => 'Example Artist/Example Album',
            'relative_path_hash' => hash('sha256', 'example artist/example album'),
            'original_release_year' => 2001,
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => 'Example Artist/Example Album/track.mp3',
            'relative_path_hash' => hash('sha256', 'example artist/example album/track.mp3'),
            'file_size' => 100,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Example Track',
            'sort_title' => 'Example Track',
        ]);
        $musician = Musician::create([
            'provider' => 'discogs',
            'provider_reference' => '801',
            'name' => 'Jamie Player',
            'sort_name' => 'Player, Jamie',
            'disambiguation' => 'session musician',
        ]);
        AlbumMusicianCredit::create([
            'album_id' => $album->id,
            'musician_id' => $musician->id,
            'provider' => 'discogs',
            'source_credit_key' => hash('sha256', 'discogs-guitar'),
            'source_entity_type' => 'release',
            'source_entity_reference' => '801',
            'relationship_type' => 'extraartist',
            'role' => 'Guitar',
            'credited_as' => 'J. Player',
            'attributes' => [],
            'is_guest' => true,
        ]);
        $manual = ManualAlbumMusicianCredit::create([
            'album_id' => $album->id,
            'musician_id' => $musician->id,
            'role' => 'Piano',
            'credited_as' => 'Jamie Player',
            'is_additional' => true,
        ]);
        $manual->tracks()->attach($track);

        $this->getJson("/api/catalog/musicians/{$musician->id}?libraryRoot={$root->id}")
            ->assertOk()
            ->assertJsonPath('albumCount', 1)
            ->assertJsonPath('trackCount', 1)
            ->assertJsonPath('firstReleaseYear', 2001)
            ->assertJsonPath('lastReleaseYear', 2001)
            ->assertJsonPath('creditedAs.0', 'J. Player')
            ->assertJsonPath('identity.sourceUrl', 'https://www.discogs.com/artist/801')
            ->assertJsonFragment(['name' => 'Guitar', 'albumCount' => 1, 'trackCount' => 0])
            ->assertJsonFragment(['name' => 'Piano', 'albumCount' => 1, 'trackCount' => 1]);

        $this->getJson("/api/catalog/albums?musician={$musician->id}&libraryRoot={$root->id}")
            ->assertOk()
            ->assertJsonPath('items.0.id', $album->id)
            ->assertJsonPath('items.0.musicianCredits.roles.0', 'Guitar')
            ->assertJsonPath('items.0.musicianCredits.roles.1', 'Piano')
            ->assertJsonPath('items.0.musicianCredits.albumWide', true)
            ->assertJsonPath('items.0.musicianCredits.trackCreditCount', 1)
            ->assertJsonPath('items.0.musicianCredits.guest', true)
            ->assertJsonPath('items.0.musicianCredits.additional', true);
    }
}
