<?php

namespace Tests\Feature;

use App\Enums\ArtworkSource;
use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Artwork;
use App\Models\Genre;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\OwnedAlbumCopy;
use App\Models\Track;
use App\Models\TrackPlayStatistic;
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
        TrackPlayStatistic::create([
            'track_id' => $track->id,
            'play_count' => 3,
            'first_played_at' => '2026-06-01 12:00:00+00',
            'last_played_at' => '2026-06-02 12:00:00+00',
        ]);
        Artist::create([
            'name' => 'Artist archive',
            'sort_name' => 'Artist archive',
            'browse_initial' => 'A',
        ]);

        $this->getJson('/api/catalog/artists?initial=A&search=Artist')
            ->assertOk()
            ->assertJsonPath('items.0.id', $artist->id)
            ->assertJsonPath('items.0.albumCount', 2)
            ->assertJsonPath('items.0.trackCount', 1)
            ->assertJsonPath('items.0.playStatistics.playCount', 3)
            ->assertJsonPath('items.0.playStatistics.playedTrackCount', 1)
            ->assertJsonPath('items.0.playStatistics.lastPlayedAt', '2026-06-02T12:00:00.000000Z')
            ->assertJsonPath('total', 1);

        $this->getJson("/api/catalog/artists/{$artist->id}")
            ->assertOk()
            ->assertJsonPath('id', $artist->id)
            ->assertJsonPath('name', 'Artist')
            ->assertJsonPath('albumCount', 2)
            ->assertJsonPath('trackCount', 1)
            ->assertJsonPath('playStatistics.playCount', 3)
            ->assertJsonPath('representativeTrackId', $track->id);

        $this->getJson('/api/catalog/albums?search=Artist')
            ->assertOk()
            ->assertJsonPath('items.0.id', $album->id)
            ->assertJsonPath('items.0.primaryArtist.name', 'Artist')
            ->assertJsonPath('items.0.trackCount', 1);

        $this->getJson('/api/catalog/albums?search=Artist%20Album')
            ->assertOk()
            ->assertJsonPath('items.0.id', $album->id)
            ->assertJsonPath('total', 2);

        $this->getJson('/api/catalog/albums?year=2001')
            ->assertOk()
            ->assertJsonPath('items.0.id', $album->id)
            ->assertJsonPath('total', 1);

        $this->getJson('/api/catalog/albums?year=1999')
            ->assertOk()
            ->assertJsonPath('items.0.id', $secondAlbum->id)
            ->assertJsonPath('total', 1);

        $this->getJson("/api/catalog/albums?genre={$genre->id}")
            ->assertOk()
            ->assertJsonPath('items.0.id', $album->id)
            ->assertJsonPath('total', 1);

        $this->getJson('/api/catalog/tracks?search=Track')
            ->assertOk()
            ->assertJsonPath('items.0.id', $track->id)
            ->assertJsonPath('items.0.streamUrl', "/api/tracks/{$track->id}/stream")
            ->assertJsonPath('items.0.year', 2001)
            ->assertJsonPath('items.0.album.title', 'Album')
            ->assertJsonPath('items.0.artists.0.name', 'Artist')
            ->assertJsonPath('items.0.playStatistics.playCount', 3);

        $this->getJson('/api/catalog/tracks?search=Artist')
            ->assertOk()
            ->assertJsonPath('items.0.id', $track->id)
            ->assertJsonPath('total', 1);

        $this->getJson('/api/catalog/tracks?search=Artist%20Track')
            ->assertOk()
            ->assertJsonPath('items.0.id', $track->id)
            ->assertJsonPath('total', 1);

        $this->getJson('/api/catalog/tracks?search=Art%20Tra')
            ->assertOk()
            ->assertJsonPath('items.0.id', $track->id)
            ->assertJsonPath('total', 1);

        $this->getJson('/api/catalog/tracks?search=rack')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->getJson('/api/catalog/tracks?search=tist')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->getJson("/api/catalog/tracks?genre={$genre->id}")
            ->assertOk()
            ->assertJsonPath('items.0.id', $track->id)
            ->assertJsonPath('total', 1);

        Genre::create(['name' => 'Rock archive']);
        $this->getJson('/api/catalog/genres?search=Rock')
            ->assertOk()
            ->assertJsonPath('items.0.id', $genre->id)
            ->assertJsonPath('items.0.trackCount', 1)
            ->assertJsonPath('total', 1);
    }

    public function test_album_detail_returns_album_metadata_and_tracks(): void
    {
        [$artist, $album, $track] = $this->createCatalog();
        $track->update(['comment' => 'Album comment']);
        $track->mediaFile->update([
            'container' => 'mp3',
            'codec' => 'mp3',
            'bitrate' => 320000,
            'raw_metadata' => [
                'audio' => [
                    'bitrate_mode' => 'cbr',
                    'encoder_options' => '-b 320',
                ],
            ],
        ]);
        $artwork = Artwork::create([
            'source_type' => ArtworkSource::Folder,
            'source_relative_path' => 'Cover/Front.jpg',
            'thumbnail_path' => 'thumbnails/example.webp',
            'mime_type' => 'image/jpeg',
            'width' => 1200,
            'height' => 1200,
            'checksum' => hash('sha256', 'example artwork'),
        ]);
        $album->update([
            'artwork_id' => $artwork->id,
            'artwork_source_type' => ArtworkSource::Folder,
            'artwork_source_relative_path' => 'Cover/Front.jpg',
        ]);

        $this->getJson("/api/catalog/albums/{$album->id}")
            ->assertOk()
            ->assertJsonPath('id', $album->id)
            ->assertJsonPath('title', 'Album')
            ->assertJsonPath('primaryArtist.id', $artist->id)
            ->assertJsonPath('primaryArtist.name', 'Artist')
            ->assertJsonPath('libraryRoot.id', $album->library_root_id)
            ->assertJsonPath('libraryRoot.name', 'Music')
            ->assertJsonPath('trackCount', 1)
            ->assertJsonPath('artworkThumbnailUrl', "/api/artwork/{$artwork->id}/thumbnail")
            ->assertJsonPath('artworkUrl', "/api/albums/{$album->id}/artwork/original")
            ->assertJsonPath('artworkWidth', 1200)
            ->assertJsonPath('artworkHeight', 1200)
            ->assertJsonPath('genres.0.id', Genre::where('name', 'Rock')->value('id'))
            ->assertJsonPath('genres.0.name', 'Rock')
            ->assertJsonPath('technical.fileTypes.0', 'MP3')
            ->assertJsonPath('technical.bitrateMinimum', 320000)
            ->assertJsonPath('technical.bitrateMaximum', 320000)
            ->assertJsonPath('technical.bitrateModes.0', 'cbr')
            ->assertJsonPath('technical.encoderSettings.0', '-b 320')
            ->assertJsonPath('tracks.0.id', $track->id)
            ->assertJsonPath('tracks.0.comment', 'Album comment')
            ->assertJsonPath('tracks.0.streamUrl', "/api/tracks/{$track->id}/stream")
            ->assertJsonPath('tracks.0.album.title', 'Album')
            ->assertJsonPath('tracks.0.album.artworkThumbnailUrl', "/api/artwork/{$artwork->id}/thumbnail")
            ->assertJsonPath('tracks.0.artists.0.name', 'Artist')
            ->assertJsonPath('tracks.0.playStatistics.playCount', 0);
    }

    public function test_album_personal_metadata_can_be_saved_and_filtered(): void
    {
        [, $album] = $this->createCatalog();
        $otherAlbum = Album::create([
            'library_root_id' => $album->library_root_id,
            'primary_artist_id' => $album->primary_artist_id,
            'title' => 'Digital Only',
            'sort_title' => 'Digital Only',
            'relative_path' => 'Artist/Digital Only',
            'relative_path_hash' => hash('sha256', 'artist/digital only'),
        ]);
        $otherTrack = $this->createTrackForAlbum($album->libraryRoot, $otherAlbum);

        $this->patchJson("/api/albums/{$album->id}/personal-metadata", [
            'purchaseSource' => 'Local store',
            'purchaseDate' => '2024-05-17',
            'hasPhysicalCopy' => true,
            'physicalFormat' => 'vinyl',
            'notes' => 'Gatefold edition',
        ])
            ->assertOk()
            ->assertJsonPath('purchaseSource', 'Local store')
            ->assertJsonPath('purchaseDate', '2024-05-17')
            ->assertJsonPath('hasPhysicalCopy', true)
            ->assertJsonPath('physicalFormat', 'vinyl')
            ->assertJsonPath('notes', 'Gatefold edition');

        $this->assertDatabaseHas('album_personal_metadata', [
            'album_id' => $album->id,
            'notes' => 'Gatefold edition',
        ]);
        $this->assertDatabaseHas('owned_album_copies', [
            'album_id' => $album->id,
            'purchase_source' => 'Local store',
            'purchase_date' => '2024-05-17',
            'is_physical' => true,
            'physical_format' => 'vinyl',
        ]);

        $this->getJson("/api/catalog/albums/{$album->id}")
            ->assertOk()
            ->assertJsonPath('personalMetadata.purchaseSource', 'Local store')
            ->assertJsonPath('personalMetadata.purchaseDate', '2024-05-17')
            ->assertJsonPath('personalMetadata.hasPhysicalCopy', true)
            ->assertJsonPath('personalMetadata.physicalFormat', 'vinyl')
            ->assertJsonPath('personalMetadata.ownedCopies.0.isPhysical', true)
            ->assertJsonPath('personalMetadata.ownedCopies.0.purchaseSource', 'Local store')
            ->assertJsonPath('tracks.0.album.personalMetadata.physicalFormat', 'vinyl');

        $this->getJson('/api/catalog/albums?physicalCopy=owned')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $album->id);

        $this->getJson('/api/catalog/albums?physicalCopy=not_owned')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $otherAlbum->id);

        $this->getJson('/api/catalog/tracks?physicalCopy=owned')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.album.id', $album->id);

        $this->getJson('/api/catalog/tracks?physicalCopy=not_owned')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $otherTrack->id);

        OwnedAlbumCopy::create([
            'album_id' => $album->id,
            'is_physical' => true,
            'physical_format' => 'cd',
            'purchase_source' => 'Second-hand shop',
        ]);

        $this->getJson("/api/catalog/albums/{$album->id}")
            ->assertOk()
            ->assertJsonCount(2, 'personalMetadata.ownedCopies')
            ->assertJsonPath('personalMetadata.ownedCopies.1.physicalFormat', 'cd');
    }

    public function test_album_and_track_browse_support_exact_artist_filters(): void
    {
        [$artist, $album, $track] = $this->createCatalog();
        $otherArtist = Artist::create([
            'name' => 'Other',
            'sort_name' => 'Other',
            'browse_initial' => 'O',
        ]);
        $otherAlbum = Album::create([
            'library_root_id' => $album->library_root_id,
            'primary_artist_id' => $otherArtist->id,
            'title' => 'Artist compilation',
            'sort_title' => 'Artist compilation',
            'relative_path' => 'Other/Artist compilation',
            'relative_path_hash' => hash('sha256', 'other/artist compilation'),
        ]);
        $otherTrack = $this->createTrackForAlbum($album->libraryRoot, $otherAlbum);
        $otherTrack->artists()->attach($otherArtist, ['role' => 'primary', 'position' => 0]);

        $this->getJson('/api/catalog/albums?search=Artist')
            ->assertOk()
            ->assertJsonPath('total', 2);
        $this->getJson("/api/catalog/albums?artist={$artist->id}")
            ->assertOk()
            ->assertJsonPath('items.0.id', $album->id)
            ->assertJsonPath('total', 1);
        $this->getJson('/api/catalog/tracks?search=Artist')
            ->assertOk()
            ->assertJsonPath('total', 2);
        $this->getJson("/api/catalog/tracks?artist={$artist->id}")
            ->assertOk()
            ->assertJsonPath('items.0.id', $track->id)
            ->assertJsonPath('total', 1);
    }

    public function test_track_only_artists_include_their_tracks_albums_in_the_catalog(): void
    {
        [, $album, $track] = $this->createCatalog();
        $trackArtist = Artist::create([
            'name' => 'Track Credit',
            'sort_name' => 'Track Credit',
            'browse_initial' => 'T',
        ]);
        $track->artists()->attach($trackArtist, ['role' => 'primary', 'position' => 1]);

        $this->getJson('/api/catalog/artists?search=Track%20Credit')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $trackArtist->id)
            ->assertJsonPath('items.0.albumCount', 1)
            ->assertJsonPath('items.0.trackCount', 1);

        $this->getJson("/api/catalog/artists/{$trackArtist->id}")
            ->assertOk()
            ->assertJsonPath('albumCount', 1)
            ->assertJsonPath('trackCount', 1)
            ->assertJsonPath('representativeTrackId', $track->id);

        $this->getJson("/api/catalog/albums?artist={$trackArtist->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $album->id);
    }

    public function test_artist_track_navigation_returns_adjacent_tracks_without_wrapping(): void
    {
        [$artist, $album, $firstTrack] = $this->createCatalog();
        $secondAlbum = Album::create([
            'library_root_id' => $album->library_root_id,
            'primary_artist_id' => $artist->id,
            'title' => 'Second Album',
            'sort_title' => 'Second Album',
            'relative_path' => 'Artist/Second Album',
            'relative_path_hash' => hash('sha256', 'artist/second album'),
            'original_release_year' => 2002,
        ]);
        $secondTrack = $this->createTrackForAlbum($album->libraryRoot, $secondAlbum);
        $secondTrack->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        $this->getJson("/api/catalog/artists/{$artist->id}/tracks/{$firstTrack->id}/navigation")
            ->assertOk()
            ->assertJsonPath('previousTrackId', null)
            ->assertJsonPath('nextTrackId', $secondTrack->id);

        $this->getJson("/api/catalog/artists/{$artist->id}/tracks/{$secondTrack->id}/navigation")
            ->assertOk()
            ->assertJsonPath('previousTrackId', $firstTrack->id)
            ->assertJsonPath('nextTrackId', null);
    }

    public function test_artist_playback_tracks_are_ordered_and_require_confirmation_for_large_actions(): void
    {
        [$artist, $album, $firstTrack] = $this->createCatalog();
        $secondAlbum = Album::create([
            'library_root_id' => $album->library_root_id,
            'primary_artist_id' => $artist->id,
            'title' => 'Second Album',
            'sort_title' => 'Second Album',
            'relative_path' => 'Artist/Second Album',
            'relative_path_hash' => hash('sha256', 'artist/second album'),
            'original_release_year' => 2002,
        ]);
        $secondTrack = $this->createTrackForAlbum($album->libraryRoot, $secondAlbum);
        $secondTrack->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        $this->getJson("/api/catalog/artists/{$artist->id}/tracks?confirmationThreshold=2")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('requiresConfirmation', true)
            ->assertJsonCount(0, 'tracks');

        $this->getJson("/api/catalog/artists/{$artist->id}/tracks")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('requiresConfirmation', false)
            ->assertJsonPath('tracks.0.id', $firstTrack->id)
            ->assertJsonPath('tracks.1.id', $secondTrack->id)
            ->assertJsonPath('tracks.0.streamUrl', "/api/tracks/{$firstTrack->id}/stream");
    }

    public function test_track_detail_returns_catalog_and_media_file_metadata(): void
    {
        [$artist, $album, $track, $genre] = $this->createCatalog();
        $track->mediaFile->update([
            'mime_type' => 'audio/mpeg',
            'container' => 'mp3',
            'codec' => 'mp3',
            'bitrate' => 320000,
            'sample_rate' => 44100,
            'channels' => 2,
            'status' => MediaFileStatus::Available,
            'raw_metadata' => [
                'audio' => [
                    'encoder' => 'LAME3.100',
                    'encoder_options' => 'V2',
                ],
            ],
        ]);

        $this->getJson("/api/catalog/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('id', $track->id)
            ->assertJsonPath('title', 'Track')
            ->assertJsonPath('streamUrl', "/api/tracks/{$track->id}/stream")
            ->assertJsonPath('album.id', $album->id)
            ->assertJsonPath('album.title', 'Album')
            ->assertJsonPath('artists.0.id', $artist->id)
            ->assertJsonPath('artists.0.name', 'Artist')
            ->assertJsonPath('genres.0.id', $genre->id)
            ->assertJsonPath('genres.0.name', 'Rock')
            ->assertJsonPath('mediaFile.libraryRoot.id', $album->library_root_id)
            ->assertJsonPath('mediaFile.libraryRoot.name', 'Music')
            ->assertJsonPath('mediaFile.relativePath', 'Artist/Album/track.mp3')
            ->assertJsonPath('mediaFile.status', MediaFileStatus::Available->value)
            ->assertJsonPath('mediaFile.mimeType', 'audio/mpeg')
            ->assertJsonPath('mediaFile.codec', 'mp3')
            ->assertJsonPath('mediaFile.encoder', 'LAME3.100')
            ->assertJsonPath('mediaFile.encoderSettings', 'V2')
            ->assertJsonPath('mediaFile.bitrate', 320000)
            ->assertJsonPath('mediaFile.sampleRate', 44100)
            ->assertJsonPath('mediaFile.channels', 2);
    }

    public function test_lame_extreme_preset_is_presented_as_v0(): void
    {
        [, $album, $track] = $this->createCatalog();
        $track->mediaFile->update([
            'raw_metadata' => [
                'audio' => [
                    'bitrate_mode' => 'vbr',
                    'encoder_options' => '--preset fast extreme -b32',
                ],
            ],
        ]);

        $this->getJson("/api/catalog/albums/{$album->id}")
            ->assertOk()
            ->assertJsonPath('technical.encoderSettings.0', 'V0');

        $this->getJson("/api/catalog/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('mediaFile.encoderSettings', 'V0');
    }

    public function test_tracks_can_be_filtered_to_never_played(): void
    {
        [, $album, $playedTrack] = $this->createCatalog();
        $neverPlayedAlbum = Album::create([
            'library_root_id' => $album->library_root_id,
            'primary_artist_id' => $album->primary_artist_id,
            'title' => 'Never Played Album',
            'sort_title' => 'Never Played Album',
            'relative_path' => 'Artist/Never Played Album',
            'relative_path_hash' => hash('sha256', 'artist/never played album'),
        ]);
        $neverPlayedTrack = $this->createTrackForAlbum($album->libraryRoot, $neverPlayedAlbum);
        TrackPlayStatistic::create([
            'track_id' => $playedTrack->id,
            'play_count' => 1,
            'first_played_at' => now(),
            'last_played_at' => now(),
        ]);

        $this->getJson('/api/catalog/tracks?playStatus=never')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $neverPlayedTrack->id);
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

    public function test_album_and_track_lists_support_explicit_sorting(): void
    {
        [, $album, $track] = $this->createCatalog();
        $olderAlbum = Album::create([
            'library_root_id' => $album->library_root_id,
            'primary_artist_id' => $album->primary_artist_id,
            'title' => 'Older Album',
            'sort_title' => 'Older Album',
            'relative_path' => 'Artist/Older Album',
            'relative_path_hash' => hash('sha256', 'artist/older album'),
            'original_release_year' => 1991,
        ]);
        $newerAlbum = Album::create([
            'library_root_id' => $album->library_root_id,
            'primary_artist_id' => $album->primary_artist_id,
            'title' => 'Newer Album',
            'sort_title' => 'Newer Album',
            'relative_path' => 'Artist/Newer Album',
            'relative_path_hash' => hash('sha256', 'artist/newer album'),
            'original_release_year' => 2020,
        ]);
        $olderTrack = $this->createTrackForAlbum($album->libraryRoot, $olderAlbum);
        $newerTrack = $this->createTrackForAlbum($album->libraryRoot, $newerAlbum);
        $track->update(['title' => 'Middle Track', 'sort_title' => 'Middle Track']);
        $olderTrack->update(['title' => 'Alpha Track', 'sort_title' => 'Alpha Track']);
        $newerTrack->update(['title' => 'Zulu Track', 'sort_title' => 'Zulu Track']);
        TrackPlayStatistic::create([
            'track_id' => $track->id,
            'play_count' => 3,
            'first_played_at' => '2026-06-01 12:00:00+00',
            'last_played_at' => '2026-06-10 12:00:00+00',
        ]);
        TrackPlayStatistic::create([
            'track_id' => $olderTrack->id,
            'play_count' => 7,
            'first_played_at' => '2026-05-01 12:00:00+00',
            'last_played_at' => '2026-06-01 12:00:00+00',
        ]);

        $this->getJson('/api/catalog/albums?sort=year_desc')
            ->assertOk()
            ->assertJsonPath('items.0.id', $newerAlbum->id)
            ->assertJsonPath('items.1.id', $album->id)
            ->assertJsonPath('items.2.id', $olderAlbum->id);

        $this->getJson('/api/catalog/albums?sort=plays')
            ->assertOk()
            ->assertJsonPath('items.0.id', $olderAlbum->id)
            ->assertJsonPath('items.1.id', $album->id)
            ->assertJsonPath('items.2.id', $newerAlbum->id);

        $this->getJson('/api/catalog/tracks?sort=title')
            ->assertOk()
            ->assertJsonPath('items.0.id', $olderTrack->id)
            ->assertJsonPath('items.1.id', $track->id)
            ->assertJsonPath('items.2.id', $newerTrack->id);

        $this->getJson('/api/catalog/tracks?sort=last_played')
            ->assertOk()
            ->assertJsonPath('items.0.id', $track->id)
            ->assertJsonPath('items.1.id', $olderTrack->id)
            ->assertJsonPath('items.2.id', $newerTrack->id);

        $this->getJson('/api/catalog/albums?sort=unsupported')->assertUnprocessable();
        $this->getJson('/api/catalog/tracks?sort=unsupported')->assertUnprocessable();
    }

    public function test_artist_filtered_albums_are_sorted_by_release_year(): void
    {
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/Music',
            'path_hash' => hash('sha256', 'd:/music'),
        ]);
        $artist = Artist::create([
            'name' => 'Discography Artist',
            'sort_name' => 'Discography Artist',
            'browse_initial' => 'D',
        ]);
        $newAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Newest',
            'sort_title' => 'Newest',
            'relative_path' => 'Discography Artist/Newest',
            'relative_path_hash' => hash('sha256', 'discography artist/newest'),
            'original_release_year' => 2005,
        ]);
        $unknownYearAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Unknown Year',
            'sort_title' => 'Unknown Year',
            'relative_path' => 'Discography Artist/Unknown Year',
            'relative_path_hash' => hash('sha256', 'discography artist/unknown year'),
        ]);
        $oldAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Oldest',
            'sort_title' => 'Oldest',
            'relative_path' => 'Discography Artist/Oldest',
            'relative_path_hash' => hash('sha256', 'discography artist/oldest'),
            'original_release_year' => 1984,
        ]);
        $sameYearAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Another 2005 Album',
            'sort_title' => 'Another 2005 Album',
            'relative_path' => 'Discography Artist/Another 2005 Album',
            'relative_path_hash' => hash('sha256', 'discography artist/another 2005 album'),
            'original_release_year' => 2005,
        ]);

        $this->createTrackForAlbum($root, $newAlbum);
        $this->createTrackForAlbum($root, $unknownYearAlbum);
        $this->createTrackForAlbum($root, $oldAlbum);
        $this->createTrackForAlbum($root, $sameYearAlbum);

        $this->getJson("/api/catalog/albums?artist={$artist->id}")
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('items.0.id', $oldAlbum->id)
            ->assertJsonPath('items.1.id', $sameYearAlbum->id)
            ->assertJsonPath('items.2.id', $newAlbum->id)
            ->assertJsonPath('items.3.id', $unknownYearAlbum->id);
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
            'original_release_year' => 2001,
        ]);
        $betaAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $betaArtist->id,
            'title' => 'Beta Album',
            'sort_title' => 'Beta Album',
            'relative_path' => 'Beta Artist/Beta Album',
            'relative_path_hash' => hash('sha256', 'beta artist/beta album'),
            'original_release_year' => 2002,
        ]);
        $alphaTrack = $this->createTrackForAlbum($root, $alphaAlbum);
        $betaTrack = $this->createTrackForAlbum($root, $betaAlbum);
        $genre = Genre::create(['name' => 'Scoped Genre']);
        $alphaTrack->genres()->attach($genre);

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

        $albumScope = [
            'search' => 'Alpha',
            'initial' => 'A',
            'year' => 2001,
            'genre' => $genre->id,
            'sort' => 'year_desc',
        ];
        $this->getJson('/api/catalog/albums?'.http_build_query($albumScope))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $alphaAlbum->id);
        $this->getJson('/api/catalog/playback/albums/random?'.http_build_query([
            'exclude' => $alphaAlbum->id,
            ...$albumScope,
        ]))
            ->assertOk()
            ->assertJsonPath('id', $alphaAlbum->id);
        $this->getJson("/api/catalog/playback/albums/{$alphaAlbum->id}/next?".http_build_query($albumScope))
            ->assertOk()
            ->assertJsonPath('id', $alphaAlbum->id);

        $this->getJson("/api/catalog/playback/tracks/{$alphaTrack->id}/next")
            ->assertOk()
            ->assertJsonPath('id', $betaTrack->id)
            ->assertJsonPath('album.id', $betaAlbum->id);

        $this->getJson("/api/catalog/playback/tracks/random?exclude={$alphaTrack->id}")
            ->assertOk()
            ->assertJsonPath('id', $betaTrack->id);

        $trackScope = [
            'search' => 'Alpha',
            'genre' => $genre->id,
            'playStatus' => 'never',
            'sort' => 'title',
        ];
        $this->getJson('/api/catalog/tracks?'.http_build_query($trackScope))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $alphaTrack->id);
        $this->getJson('/api/catalog/playback/tracks/random?'.http_build_query([
            'exclude' => $alphaTrack->id,
            ...$trackScope,
        ]))
            ->assertOk()
            ->assertJsonPath('id', $alphaTrack->id);
        $this->getJson("/api/catalog/playback/tracks/{$alphaTrack->id}/next?".http_build_query($trackScope))
            ->assertOk()
            ->assertJsonPath('id', $alphaTrack->id);
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
            'year' => 2001,
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
