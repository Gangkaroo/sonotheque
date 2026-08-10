<?php

namespace Tests\Feature;

use App\Enums\OnlineContentStatus;
use App\Models\Album;
use App\Models\AlbumMusicianCredit;
use App\Models\AlbumMusicianCreditSuppression;
use App\Models\AlbumMusicianEnrichment;
use App\Models\Artist;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\ManualAlbumMusicianCredit;
use App\Models\MediaFile;
use App\Models\Musician;
use App\Models\Track;
use App\Music\Enrichment\AlbumMusicianCreditManager;
use App\Music\Enrichment\MusicianCreditSourceKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MusicianCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_effective_musicians_with_root_scoped_credit_counts_and_coverage(): void
    {
        $firstRoot = $this->createRoot('First', 'D:/First');
        $secondRoot = $this->createRoot('Second', 'E:/Second');
        [$firstAlbum, $firstTrack] = $this->createAlbumWithTrack($firstRoot, 'First Album');
        [$secondAlbum, $secondTrack] = $this->createAlbumWithTrack($secondRoot, 'Second Album');
        $imported = $this->createImportedCredit($firstAlbum, $firstTrack, 'Jamie Player');
        $manualMusician = Musician::create([
            'provider' => 'manual',
            'provider_reference' => 'manual-player',
            'name' => 'Alex Session',
            'sort_name' => 'Session, Alex',
        ]);
        $manual = ManualAlbumMusicianCredit::create([
            'album_id' => $firstAlbum->id,
            'musician_id' => $manualMusician->id,
            'role' => 'Piano',
        ]);
        $manual->tracks()->attach($firstTrack->id);
        $this->createImportedCredit($secondAlbum, $secondTrack, 'Second Root Player');
        $hidden = $this->createImportedCredit($firstAlbum, null, 'Hidden Player');
        AlbumMusicianCreditSuppression::create([
            'album_id' => $firstAlbum->id,
            'provider' => $hidden->provider,
            'source_credit_key' => $hidden->source_credit_key,
        ]);
        AlbumMusicianEnrichment::create([
            'album_id' => $firstAlbum->id,
            'provider' => 'musicbrainz',
            'lookup_version' => AlbumMusicianCreditManager::LOOKUP_VERSION,
            'status' => OnlineContentStatus::Ready,
        ]);

        $this->getJson("/api/catalog/musicians?libraryRoot={$firstRoot->id}")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('coverage.checkedAlbums', 1)
            ->assertJsonPath('coverage.creditedAlbums', 1)
            ->assertJsonPath('coverage.totalAlbums', 1)
            ->assertJsonPath('coverage.percentage', 100)
            ->assertJsonPath('items.0.name', 'Jamie Player')
            ->assertJsonPath('items.0.albumCount', 1)
            ->assertJsonPath('items.0.trackCount', 1)
            ->assertJsonPath('items.1.name', 'Alex Session')
            ->assertJsonMissing(['name' => 'Hidden Player'])
            ->assertJsonMissing(['name' => 'Second Root Player']);

        $this->getJson('/api/catalog/musicians?search=Second')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.name', 'Second Root Player');

        $this->getJson('/api/catalog/musicians?initial=S')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->getJson("/api/catalog/musicians/{$imported->musician_id}?libraryRoot={$firstRoot->id}")
            ->assertOk()
            ->assertJsonPath('id', $imported->musician_id)
            ->assertJsonPath('name', 'Jamie Player')
            ->assertJsonPath('albumCount', 1)
            ->assertJsonPath('trackCount', 1);

        $this->getJson("/api/catalog/musicians/{$imported->musician_id}?libraryRoot={$secondRoot->id}")
            ->assertNotFound();

        $this->getJson("/api/dashboard-metrics?libraryRoot={$firstRoot->id}")
            ->assertOk()
            ->assertJsonPath('musicians', 2);

        $this->getJson("/api/catalog/albums?musician={$imported->musician_id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $firstAlbum->id);
        $this->getJson("/api/catalog/tracks?musician={$imported->musician_id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $firstTrack->id);
        $this->getJson("/api/catalog/albums?musician={$hidden->musician_id}")
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    private function createRoot(string $name, string $path): LibraryRoot
    {
        $library = Library::query()->firstOrCreate(['name' => 'Test']);

        return $library->roots()->create([
            'name' => $name,
            'path' => $path,
            'path_hash' => hash('sha256', strtolower($path)),
        ]);
    }

    /** @return array{Album, Track} */
    private function createAlbumWithTrack(LibraryRoot $root, string $title): array
    {
        $artist = Artist::create([
            'name' => $title.' Artist',
            'sort_name' => $title.' Artist',
            'browse_initial' => strtoupper($title[0]),
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => $title,
            'sort_title' => $title,
            'relative_path' => $title,
            'relative_path_hash' => hash('sha256', strtolower($title)),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => $title.'/track.mp3',
            'relative_path_hash' => hash('sha256', strtolower($title).'/track.mp3'),
            'file_size' => 100,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $title.' Track',
            'sort_title' => $title.' Track',
        ]);

        return [$album, $track];
    }

    private function createImportedCredit(Album $album, ?Track $track, string $name): AlbumMusicianCredit
    {
        $reference = strtolower(str_replace(' ', '-', $name));
        $musician = Musician::create([
            'provider' => 'musicbrainz',
            'provider_reference' => $reference,
            'name' => $name,
            'sort_name' => $name,
        ]);
        $sourceType = $track === null ? 'release' : 'recording';
        $sourceReference = $track === null ? 'release-'.$album->id : 'track-'.$track->id;
        $sourceKey = MusicianCreditSourceKey::make(
            provider: 'musicbrainz',
            musicianProvider: $musician->provider,
            musicianReference: $musician->provider_reference,
            sourceEntityType: $sourceType,
            sourceEntityReference: $sourceReference,
            relationshipType: 'instrument',
            role: 'Guitar',
            creditedAs: null,
            guest: false,
            additional: false,
        );

        return AlbumMusicianCredit::create([
            'album_id' => $album->id,
            'track_id' => $track?->id,
            'musician_id' => $musician->id,
            'provider' => 'musicbrainz',
            'source_credit_key' => $sourceKey,
            'source_entity_type' => $sourceType,
            'source_entity_reference' => $sourceReference,
            'relationship_type' => 'instrument',
            'role' => 'Guitar',
            'attributes' => [],
        ]);
    }
}
