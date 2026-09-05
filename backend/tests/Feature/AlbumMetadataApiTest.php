<?php

namespace Tests\Feature;

use App\Jobs\ApplyAlbumMetadataEdit;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\MetadataEditItem;
use App\Models\RecordLabel;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlbumMetadataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_previews_every_file_and_queues_an_album_batch(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $values = $this->values();

        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonPath('supportedFiles', 2)
            ->assertJsonCount(2, 'files')
            ->assertJsonPath('changes.0.field', 'albumTitle')
            ->assertJsonPath('trackArtistsWillChange', true)
            ->assertJsonPath('files.0.writeValues.artistNames.0', 'Changed artist')
            ->json();

        $response = $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted()
            ->assertJsonPath('type', 'album')
            ->assertJsonPath('totalItems', 2);

        $this->assertDatabaseCount('metadata_edit_items', 2);
        $this->assertDatabaseHas('metadata_edit_jobs', [
            'id' => $response->json('id'),
            'album_id' => $album->id,
            'status' => 'pending',
        ]);
        Queue::assertPushed(ApplyAlbumMetadataEdit::class);
    }

    public function test_it_can_preserve_track_artists_when_the_album_artist_changes(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $values = [
            ...$this->values(),
            'updateTrackArtists' => false,
        ];

        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonPath('trackArtistsWillChange', false)
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted();

        $this->assertArrayNotHasKey('artistNames', MetadataEditItem::firstOrFail()->requested_changes);
    }

    public function test_it_rejects_mixed_format_batches_before_writing(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.m4a']);
        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $this->values())
            ->assertOk()
            ->assertJsonPath('unsupportedFiles', 1)
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$this->values(),
            'fingerprint' => $preview['fingerprint'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('album');
        Queue::assertNothingPushed();
    }

    public function test_it_reports_id3v2_unsynchronization_before_queueing_a_batch(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $album->tracks->first()->mediaFile->update([
            'raw_metadata' => ['id3v2' => ['majorversion' => 3, 'flags' => ['unsynch' => true]]],
        ]);

        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $this->values())
            ->assertOk()
            ->assertJsonPath('unsupportedFiles', 1)
            ->assertJsonPath('files.0.supportIssue', 'id3v2_unsynchronization')
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$this->values(),
            'fingerprint' => $preview['fingerprint'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('album');
        Queue::assertNothingPushed();
    }

    public function test_it_only_writes_shared_fields_that_actually_changed(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $values = [
            'albumTitle' => 'Changed album',
            'albumArtist' => 'Artist',
            'releaseYear' => 2000,
            'totalDiscs' => null,
            'genres' => [],
        ];
        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonCount(1, 'changes')
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted();

        $this->assertSame(['albumTitle' => 'Changed album'], MetadataEditItem::firstOrFail()->requested_changes);
    }

    public function test_it_synchronizes_missing_album_artist_file_tags_when_the_catalog_value_is_unchanged(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $album->tracks()->orderBy('track_number')->get()[1]->mediaFile->update([
            'raw_metadata' => ['comments' => ['artist' => ['Artist']]],
        ]);
        $values = [
            'albumTitle' => 'Album',
            'albumArtist' => 'Artist',
            'releaseYear' => 2000,
            'totalDiscs' => null,
            'genres' => [],
        ];

        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.field', 'albumArtist')
            ->assertJsonPath('changes.0.current', 'Artist')
            ->assertJsonPath('changes.0.proposed', 'Artist')
            ->assertJsonPath('changes.0.fileValuesDiffer', true)
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted();

        $this->assertSame(
            [['albumArtist' => 'Artist'], ['albumArtist' => 'Artist']],
            MetadataEditItem::query()->orderBy('id')->pluck('requested_changes')->all(),
        );
        Queue::assertPushed(ApplyAlbumMetadataEdit::class);
    }

    public function test_it_can_repair_track_artists_after_the_album_artist_was_already_changed(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $correctArtist = Artist::create([
            'name' => 'Correct artist',
            'sort_name' => 'Correct artist',
            'browse_initial' => 'C',
        ]);
        $album->update(['primary_artist_id' => $correctArtist->id]);
        $album->mediaFiles()->update([
            'raw_metadata' => ['comments' => ['album_artist' => ['Correct artist']]],
        ]);
        $values = [
            'albumTitle' => 'Album',
            'albumArtist' => 'Correct artist',
            'updateTrackArtists' => true,
            'releaseYear' => 2000,
            'totalDiscs' => null,
            'genres' => [],
        ];

        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.field', 'albumArtist')
            ->assertJsonPath('changes.0.current', 'Correct artist')
            ->assertJsonPath('changes.0.proposed', 'Correct artist')
            ->assertJsonPath('changes.0.fileValuesDiffer', true)
            ->assertJsonPath('trackArtistsWillChange', true)
            ->assertJsonPath('files.0.writeValues.artistNames.0', 'Correct artist')
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted();

        Queue::assertPushed(ApplyAlbumMetadataEdit::class);
    }

    public function test_it_can_clear_the_comment_on_every_album_track(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $album->tracks()->update(['comment' => 'Remove me']);
        $values = [
            'albumTitle' => 'Album',
            'albumArtist' => 'Artist',
            'releaseYear' => 2000,
            'totalDiscs' => null,
            'genres' => [],
            'comment' => null,
        ];

        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.field', 'comment')
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted();

        $this->assertSame(['comment' => null], MetadataEditItem::firstOrFail()->requested_changes);
        $this->assertSame(
            [['comment' => null], ['comment' => null]],
            MetadataEditItem::query()->orderBy('id')->pluck('requested_changes')->all(),
        );
    }

    public function test_case_only_artist_changes_are_written_to_every_track(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $values = [
            'albumTitle' => 'Album', 'albumArtist' => 'ARTIST', 'updateTrackArtists' => true,
            'releaseYear' => 2000, 'totalDiscs' => null, 'genres' => [],
        ];

        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonPath('trackArtistsWillChange', true)
            ->assertJsonPath('files.0.writeValues.artistNames', ['ARTIST'])
            ->assertJsonPath('files.1.writeValues.artistNames', ['ARTIST'])
            ->json();
        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$values, 'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted();
        foreach (MetadataEditItem::all() as $item) {
            $this->assertSame(['ARTIST'], $item->requested_changes['artistNames']);
        }
    }

    public function test_it_repairs_file_artist_casing_when_catalog_and_album_artist_are_already_correct(): void
    {
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $album->mediaFiles()->update(['raw_metadata' => ['comments' => [
            'album_artist' => ['Artist'], 'artist' => ['ARTIST'],
        ]]]);
        $values = [
            'albumTitle' => 'Album', 'albumArtist' => 'Artist', 'updateTrackArtists' => true,
            'releaseYear' => 2000, 'totalDiscs' => null, 'genres' => [],
        ];

        $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonPath('trackArtistsWillChange', true)
            ->assertJsonPath('changes.0.fileValuesDiffer', true)
            ->assertJsonPath('files.0.writeValues.artistNames', ['Artist'])
            ->assertJsonPath('files.1.writeValues.artistNames', ['Artist']);

        $this->postJson("/api/albums/{$album->id}/metadata/preview", [
            ...$values, 'updateTrackArtists' => false,
        ])->assertOk()->assertJsonPath('changes', [])->assertJsonPath('files', []);
    }

    public function test_it_can_repair_record_label_tags_that_are_missing_from_some_album_files(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $recordLabel = RecordLabel::create([
            'name' => 'InsideOut Music',
            'normalized_name' => 'insideout music',
        ]);
        $album->recordLabelAssignments()->create([
            'record_label_id' => $recordLabel->id,
            'catalog_number' => 'IOMCD 123',
            'catalog_number_hash' => hash('sha256', 'iomcd 123'),
            'source' => 'file_tag',
        ]);
        $album->mediaFiles()->orderBy('id')->firstOrFail()->update([
            'raw_metadata' => [
                'comments' => [
                    'album_artist' => ['Artist'],
                    'artist' => ['Artist'],
                    'publisher' => ['InsideOut Music'],
                    'catalognumber' => ['IOMCD 123'],
                ],
            ],
        ]);
        $values = [
            'albumTitle' => 'Album',
            'albumArtist' => 'Artist',
            'releaseYear' => 2000,
            'totalDiscs' => null,
            'genres' => [],
            'recordLabels' => [[
                'name' => 'InsideOut Music',
                'catalogNumber' => 'IOMCD 123',
            ]],
        ];

        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.field', 'recordLabels')
            ->assertJsonPath('changes.0.fileValuesDiffer', true)
            ->assertJsonPath('files.0.writeValues.recordLabels.0.name', 'InsideOut Music')
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted();

        $this->assertSame(
            [$values['recordLabels'], $values['recordLabels']],
            MetadataEditItem::query()->orderBy('id')->pluck('requested_changes')->map(
                fn (array $changes): array => $changes['recordLabels'],
            )->all(),
        );
    }

    public function test_it_can_remove_an_additional_tag_from_every_file_where_it_occurs(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3', '02.mp3']);
        $tracks = $album->tracks()->orderBy('track_number')->get();
        $tracks[0]->mediaFile->update(['raw_metadata' => $this->additionalTags('Bandcamp')]);
        $tracks[1]->mediaFile->update(['raw_metadata' => $this->additionalTags('CD rip')]);
        $values = [
            'albumTitle' => 'Album',
            'albumArtist' => 'Artist',
            'releaseYear' => 2000,
            'totalDiscs' => null,
            'genres' => [],
            'removedTagKeys' => ['TXXX:SOURCE'],
        ];

        $this->getJson("/api/catalog/albums/{$album->id}")
            ->assertOk()
            ->assertJsonPath('additionalTags.0.key', 'TXXX:SOURCE')
            ->assertJsonPath('additionalTags.0.trackCount', 2)
            ->assertJsonPath('additionalTags.0.protectedFromRemoval', false)
            ->assertJsonCount(2, 'additionalTags.0.values');

        $preview = $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertOk()
            ->assertJsonPath('changes.0.field', 'removedTagKeys')
            ->assertJsonPath('changes.0.current.0', 'Source')
            ->assertJsonPath('files.0.writeValues.removedTagKeys.0', 'TXXX:SOURCE')
            ->json();

        $this->postJson("/api/albums/{$album->id}/metadata-edits", [
            ...$values,
            'fingerprint' => $preview['fingerprint'],
        ])->assertAccepted();

        $this->assertSame(
            [['removedTagKeys' => ['TXXX:SOURCE']], ['removedTagKeys' => ['TXXX:SOURCE']]],
            MetadataEditItem::query()->orderBy('id')->pluck('requested_changes')->all(),
        );
    }

    public function test_it_protects_synchronized_playback_statistics_tags_from_album_removal(): void
    {
        Queue::fake();
        $album = $this->createAlbum(['01.mp3']);
        $album->tracks->first()->mediaFile->update([
            'raw_metadata' => $this->additionalTags('Bandcamp', playCount: 12),
        ]);
        ApplicationSetting::current()->update([
            'import_play_statistics_from_tags' => true,
            'export_play_statistics_to_tags' => true,
        ]);
        $values = [
            'albumTitle' => 'Album',
            'albumArtist' => 'Artist',
            'releaseYear' => 2000,
            'totalDiscs' => null,
            'genres' => [],
            'removedTagKeys' => ['TXXX:PLAY_COUNT'],
        ];

        $this->getJson("/api/catalog/albums/{$album->id}")
            ->assertOk()
            ->assertJsonPath('additionalTags.0.key', 'TXXX:PLAY_COUNT')
            ->assertJsonPath('additionalTags.0.protectedFromRemoval', true);

        $this->postJson("/api/albums/{$album->id}/metadata/preview", $values)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('removedTagKeys');
        Queue::assertNothingPushed();
    }

    /** @return array{albumTitle: string, albumArtist: string, releaseYear: int, totalDiscs: int, genres: list<string>} */
    private function values(): array
    {
        return [
            'albumTitle' => 'Changed album',
            'albumArtist' => 'Changed artist',
            'releaseYear' => 2025,
            'totalDiscs' => 2,
            'genres' => ['Doom', 'Metal'],
        ];
    }

    /** @return array<string, mixed> */
    private function additionalTags(string $source, ?int $playCount = null): array
    {
        $frames = [[
            'framenamelong' => 'User defined text information frame',
            'description' => 'Source',
            'data' => $source,
        ]];
        $comments = [
            'album_artist' => ['Artist'],
            'artist' => ['Artist'],
            'source' => [$source],
        ];
        if ($playCount !== null) {
            array_unshift($frames, [
                'framenamelong' => 'User defined text information frame',
                'description' => 'PLAY_COUNT',
                'data' => (string) $playCount,
            ]);
            $comments['play_count'] = [(string) $playCount];
        }

        return ['id3v2' => ['comments' => $comments, 'TXXX' => $frames]];
    }

    /** @param list<string> $filenames */
    private function createAlbum(array $filenames): Album
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
            'original_release_year' => 2000,
        ]);
        foreach ($filenames as $position => $filename) {
            $mediaFile = MediaFile::create([
                'library_root_id' => $root->id,
                'album_id' => $album->id,
                'relative_path' => "Artist/Album/{$filename}",
                'relative_path_hash' => hash('sha256', "artist/album/{$filename}"),
                'file_size' => 1,
                'modified_at' => now(),
                'last_seen_at' => now(),
                'raw_metadata' => [
                    'comments' => [
                        'album_artist' => ['Artist'],
                        'artist' => ['Artist'],
                    ],
                ],
            ]);
            $track = Track::create([
                'album_id' => $album->id,
                'media_file_id' => $mediaFile->id,
                'title' => 'Track '.($position + 1),
                'sort_title' => 'Track '.($position + 1),
                'disc_number' => 1,
                'track_number' => $position + 1,
            ]);
            $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);
        }

        return $album;
    }
}
