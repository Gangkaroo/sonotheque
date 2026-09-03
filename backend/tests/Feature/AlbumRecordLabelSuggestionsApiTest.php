<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\AlbumMusicianEnrichment;
use App\Models\AlbumRecordLabel;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\RecordLabel;
use App\Models\Track;
use App\Enums\OnlineContentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlbumRecordLabelSuggestionsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_exact_musicbrainz_and_linked_discogs_release_suggestions(): void
    {
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        $album = $this->createAlbum();
        ApplicationSetting::current()->update([
            'online_information_enabled' => true,
            'discogs_personal_access_token' => 'token',
            'discogs_username' => 'collector',
            'discogs_user_id' => 123,
        ]);
        $album->ownedCopies()->create([
            'is_physical' => true,
            'provider' => 'discogs',
            'external_release_id' => 456,
        ]);
        $currentLabel = RecordLabel::create([
            'name' => 'Old Label',
            'normalized_name' => 'old label',
        ]);
        $album->recordLabelAssignments()->create([
            'record_label_id' => $currentLabel->id,
            'catalog_number' => 'OLD-1',
            'catalog_number_hash' => hash('sha256', 'old-1'),
            'source' => 'file_tag',
        ]);
        Http::fake([
            '*musicbrainz.org*' => Http::response([
                'id' => '18d5d0ca-1107-4df2-9d51-df1c5fe57490',
                'title' => 'Album',
                'artist-credit' => [['name' => 'Artist']],
                'label-info' => [[
                    'label' => ['name' => 'MusicBrainz Label'],
                    'catalog-number' => 'MB-001',
                ]],
                'release-group' => ['primary-type' => 'Album'],
            ]),
            'api.discogs.com/releases/456' => Http::response([
                'id' => 456,
                'title' => 'Album',
                'artists_sort' => 'Artist',
                'uri' => 'https://www.discogs.com/release/456-Artist-Album',
                'labels' => [[
                    'name' => 'Discogs Label',
                    'catno' => 'DG-001',
                ]],
            ]),
        ]);

        $this->getJson("/api/albums/{$album->id}/record-label-suggestions")
            ->assertOk()
            ->assertJsonPath('current.0.name', 'Old Label')
            ->assertJsonPath('suggestions.0.provider', 'musicbrainz')
            ->assertJsonPath('suggestions.0.recordLabels.0.name', 'MusicBrainz Label')
            ->assertJsonPath('suggestions.0.recordLabels.0.catalogNumber', 'MB-001')
            ->assertJsonPath('suggestions.0.matchesCurrent', false)
            ->assertJsonPath('suggestions.1.provider', 'discogs')
            ->assertJsonPath('suggestions.1.recordLabels.0.name', 'Discogs Label')
            ->assertJsonPath('suggestions.1.recordLabels.0.catalogNumber', 'DG-001')
            ->assertJsonCount(0, 'errors');

        $this->postJson("/api/albums/{$album->id}/record-label-suggestions/confirm", [
            'provider' => 'musicbrainz',
            'sourceReference' => '18d5d0ca-1107-4df2-9d51-df1c5fe57490',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('suggestion');
        $this->assertDatabaseMissing(AlbumRecordLabel::class, [
            'album_id' => $album->id,
            'source' => 'musicbrainz',
        ]);
    }

    public function test_it_confirms_a_matching_provider_source_without_rewriting_metadata(): void
    {
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        $album = $this->createAlbum();
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        $currentLabel = RecordLabel::create([
            'name' => 'MusicBrainz Label',
            'normalized_name' => 'musicbrainz label',
        ]);
        $album->recordLabelAssignments()->create([
            'record_label_id' => $currentLabel->id,
            'catalog_number' => 'MB-001',
            'catalog_number_hash' => hash('sha256', 'mb-001'),
            'source' => 'file_tag',
        ]);
        $otherLabel = RecordLabel::create([
            'name' => 'Existing Discogs Label',
            'normalized_name' => 'existing discogs label',
        ]);
        $existingProviderAssignment = $album->recordLabelAssignments()->create([
            'record_label_id' => $otherLabel->id,
            'catalog_number' => null,
            'catalog_number_hash' => hash('sha256', ''),
            'source' => 'discogs',
            'source_reference' => '789',
        ]);
        Http::fake([
            '*musicbrainz.org*' => Http::response([
                'id' => '18d5d0ca-1107-4df2-9d51-df1c5fe57490',
                'title' => 'Album',
                'artist-credit' => [['name' => 'Artist']],
                'label-info' => [[
                    'label' => ['name' => 'MusicBrainz Label'],
                    'catalog-number' => 'MB-001',
                ]],
                'release-group' => ['primary-type' => 'Album'],
            ]),
        ]);

        $this->postJson("/api/albums/{$album->id}/record-label-suggestions/confirm", [
            'provider' => 'musicbrainz',
            'sourceReference' => '18d5d0ca-1107-4df2-9d51-df1c5fe57490',
        ])
            ->assertOk()
            ->assertJsonPath('suggestions.0.matchesCurrent', true)
            ->assertJsonPath('suggestions.0.sourceConfirmed', true);

        $this->assertDatabaseHas(AlbumRecordLabel::class, [
            'album_id' => $album->id,
            'record_label_id' => $currentLabel->id,
            'source' => 'musicbrainz',
            'source_reference' => '18d5d0ca-1107-4df2-9d51-df1c5fe57490',
        ]);
        $this->assertDatabaseHas(AlbumRecordLabel::class, [
            'id' => $existingProviderAssignment->id,
            'source_reference' => '789',
        ]);
        $this->assertDatabaseCount('metadata_edit_jobs', 0);
    }

    public function test_it_reuses_ambiguous_musicbrainz_release_candidates_for_untagged_albums(): void
    {
        Queue::fake();
        config(['sonotheque.enrichment.providers.musicbrainz.minimum_interval_ms' => 0]);
        $album = $this->createAlbum();
        $album->tracks->first()->mediaFile->update(['raw_metadata' => []]);
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        AlbumMusicianEnrichment::create([
            'album_id' => $album->id,
            'provider' => 'musicbrainz',
            'lookup_version' => 4,
            'status' => OnlineContentStatus::Ambiguous,
            'candidate_releases' => [[
                'id' => 'candidate-release-id',
                'date' => '1986-03-03',
                'country' => 'US',
                'formats' => ['CD'],
                'trackCount' => 8,
            ]],
        ]);
        Http::fake([
            '*musicbrainz.org*' => Http::response([
                'id' => 'candidate-release-id',
                'title' => 'Album',
                'artist-credit' => [['name' => 'Artist']],
                'label-info' => [[
                    'label' => ['name' => 'Candidate Label'],
                    'catalog-number' => 'CAT-1986',
                ]],
                'release-group' => ['primary-type' => 'Album'],
            ]),
        ]);

        $this->getJson("/api/albums/{$album->id}/record-label-suggestions")
            ->assertOk()
            ->assertJsonPath('suggestions.0.provider', 'musicbrainz')
            ->assertJsonPath('suggestions.0.sourceReference', 'candidate-release-id')
            ->assertJsonPath('suggestions.0.sourceDescription', '1986-03-03 · US · CD')
            ->assertJsonPath('suggestions.0.sourceTrackCount', 8)
            ->assertJsonPath('suggestions.0.recordLabels.0.name', 'Candidate Label')
            ->assertJsonPath('suggestions.0.recordLabels.0.catalogNumber', 'CAT-1986');

        $this->postJson("/api/albums/{$album->id}/record-label-suggestions/select", [
            'provider' => 'musicbrainz',
            'sourceReference' => 'candidate-release-id',
        ])
            ->assertOk()
            ->assertJsonPath('musicianReleaseResolved', true);
        $this->assertDatabaseHas('album_musician_enrichments', [
            'album_id' => $album->id,
            'selected_release_id' => 'candidate-release-id',
            'status' => OnlineContentStatus::Pending->value,
        ]);
    }

    private function createAlbum(): Album
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
            'relative_path' => 'Artist/Album/01.mp3',
            'relative_path_hash' => hash('sha256', 'artist/album/01.mp3'),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
            'raw_metadata' => [
                'comments' => [
                    'musicbrainz_albumid' => ['18d5d0ca-1107-4df2-9d51-df1c5fe57490'],
                ],
            ],
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Track',
            'sort_title' => 'Track',
            'track_number' => 1,
        ]);
        $track->artists()->attach($artist->id, ['role' => 'primary', 'position' => 0]);

        return $album;
    }
}
