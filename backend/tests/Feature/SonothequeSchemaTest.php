<?php

namespace Tests\Feature;

use App\Enums\ArtworkSource;
use App\Enums\MediaFileStatus;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Artwork;
use App\Models\Genre;
use App\Models\Library;
use App\Models\MediaFile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SonothequeSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sonotheque_schema_uses_postgresql_jsonb_columns(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        foreach (['application_settings', 'libraries', 'library_roots', 'scan_runs', 'artists', 'genres', 'artwork', 'albums', 'media_files', 'tracks', 'metadata_backups', 'online_content_cache', 'audio_analysis_profiles', 'audio_analysis_artifacts', 'audio_analysis_runs', 'audio_analysis_run_items', 'audio_similarity_feedback'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table [{$table}] to exist.");
        }

        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertFalse(Schema::hasColumn('sessions', 'user_id'));
        $this->assertFalse(Schema::hasColumn('library_roots', 'cover_image_path'));
        $this->assertTrue(Schema::hasColumn('scan_runs', 'files_removed'));
        $this->assertTrue(Schema::hasColumn('scan_runs', 'subtree_path'));
        $this->assertTrue(Schema::hasColumn('media_files', 'metadata_parser_version'));
        $this->assertTrue(Schema::hasColumn('media_files', 'content_fingerprint'));
        $this->assertTrue(Schema::hasColumn('media_files', 'content_fingerprint_version'));
        $this->assertTrue(Schema::hasColumn('application_settings', 'audio_intelligence_enabled'));
        $this->assertTrue(Schema::hasColumn('application_settings', 'audio_intelligence_sample_size'));
        $this->assertTrue(Schema::hasColumn('audio_analysis_run_items', 'content_fingerprint_version'));
        $this->assertTrue(Schema::hasColumn('audio_analysis_runs', 'audio_analysis_profile_id'));
        $this->assertTrue(Schema::hasColumn('audio_analysis_runs', 'phase'));
        $this->assertTrue(Schema::hasColumn('audio_analysis_runs', 'cancel_requested_at'));
        $this->assertTrue(Schema::hasColumn('audio_analysis_runs', 'heartbeat_at'));
        $this->assertTrue(Schema::hasColumn('audio_analysis_run_items', 'audio_analysis_artifact_id'));
        $this->assertFalse(Schema::hasTable('audio_analysis_results'));
        $this->assertTrue(Schema::hasColumn('albums', 'artwork_source_type'));
        $this->assertTrue(Schema::hasColumn('albums', 'artwork_source_relative_path'));
        $this->assertFalse(Schema::hasColumn('artwork', 'cache_path'));
        $this->assertFalse(Schema::hasColumn('scan_runs', 'files_missing'));

        foreach ([
            ['library_roots', 'include_patterns'],
            ['library_roots', 'exclude_patterns'],
            ['library_roots', 'cover_image_paths'],
            ['library_roots', 'excluded_directories'],
            ['scan_runs', 'summary'],
            ['albums', 'metadata'],
            ['media_files', 'raw_metadata'],
            ['tracks', 'metadata'],
            ['tracks', 'composers'],
            ['tracks', 'performers'],
            ['online_content_cache', 'lookup'],
            ['online_content_cache', 'payload'],
            ['audio_analysis_runs', 'summary'],
            ['audio_analysis_profiles', 'manifest'],
            ['audio_analysis_artifacts', 'features'],
            ['audio_analysis_artifacts', 'embedding'],
            ['audio_analysis_artifacts', 'timings'],
            ['audio_analysis_artifacts', 'hardware'],
        ] as [$table, $column]) {
            $this->assertTrue(
                DB::table('information_schema.columns')
                    ->where('table_schema', 'public')
                    ->where('table_name', $table)
                    ->where('column_name', $column)
                    ->where('data_type', 'jsonb')
                    ->exists(),
                "Expected [{$table}.{$column}] to use PostgreSQL jsonb.",
            );
        }
    }

    public function test_models_cast_metadata_and_connect_the_library_graph(): void
    {
        $library = Library::create(['name' => 'Home Music']);
        $root = $library->roots()->create([
            'name' => 'Primary HDD',
            'path' => 'D:\\Music',
            'path_hash' => hash('sha256', 'd:\\music'),
            'cover_image_paths' => ['artwork/front.jpg', 'Disc 1/front.jpg'],
            'excluded_directories' => ['Incoming'],
            'include_patterns' => ['*.flac', '*.mp3'],
        ]);

        $scan = $root->scanRuns()->create([
            'status' => ScanStatus::Running,
            'trigger' => ScanTrigger::Manual,
            'started_at' => now(),
            'summary' => ['phase' => 'discovering'],
        ]);

        $artist = Artist::create([
            'name' => 'Example Artist',
            'sort_name' => 'Example Artist',
            'browse_initial' => 'E',
        ]);
        $artwork = Artwork::create([
            'source_type' => ArtworkSource::Folder,
            'source_relative_path' => 'artwork/front.jpg',
            'thumbnail_path' => 'artwork/thumbnails/example.webp',
            'mime_type' => 'image/webp',
            'width' => 1000,
            'height' => 1000,
            'checksum' => hash('sha256', 'example artwork'),
        ]);
        $album = $root->albums()->create([
            'primary_artist_id' => $artist->id,
            'artwork_id' => $artwork->id,
            'title' => 'Example Album',
            'relative_path' => 'Example Artist\\Example Album',
            'relative_path_hash' => hash('sha256', 'example artist\\example album'),
            'original_release_year' => 2026,
            'metadata' => ['source' => 'folder'],
        ]);
        $mediaFile = $album->mediaFiles()->create([
            'library_root_id' => $root->id,
            'relative_path' => 'Example Artist\\Example Album\\01 - Track.flac',
            'relative_path_hash' => hash('sha256', 'example artist\\example album\\01 - track.flac'),
            'file_size' => 12345678,
            'modified_at' => now(),
            'status' => MediaFileStatus::Available,
            'last_seen_at' => now(),
            'raw_metadata' => ['format' => 'flac'],
        ]);
        $track = $album->tracks()->create([
            'media_file_id' => $mediaFile->id,
            'title' => 'Track',
            'duration_ms' => 180000,
            'track_number' => 1,
            'disc_number' => 1,
        ]);
        $genre = Genre::create(['name' => 'Electronic']);

        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);
        $track->genres()->attach($genre);

        $this->assertSame(ScanStatus::Running, $scan->fresh()->status);
        $this->assertSame(ScanTrigger::Manual, $scan->fresh()->trigger);
        $this->assertTrue($library->albums()->whereKey($album->id)->exists());
        $this->assertTrue($library->mediaFiles()->whereKey($mediaFile->id)->exists());
        $this->assertTrue($library->scanRuns()->whereKey($scan->id)->exists());
        $this->assertTrue($root->fresh()->library->is($library));
        $this->assertSame(['*.flac', '*.mp3'], $root->fresh()->include_patterns);
        $this->assertSame(['artwork/front.jpg', 'Disc 1/front.jpg'], $root->fresh()->cover_image_paths);
        $this->assertSame(['Incoming'], $root->fresh()->excluded_directories);
        $this->assertSame(ArtworkSource::Folder, $album->fresh()->artwork->source_type);
        $this->assertSame(MediaFileStatus::Available, $mediaFile->fresh()->status);
        $this->assertTrue($artist->tracks()->whereKey($track->id)->exists());
        $this->assertTrue($genre->tracks()->whereKey($track->id)->exists());
        $this->assertTrue($mediaFile->track()->whereKey($track->id)->exists());
        $this->assertSame(2026, $album->fresh()->original_release_year);
    }

    public function test_relative_file_paths_are_unique_within_a_library_root(): void
    {
        $library = Library::create(['name' => 'Home Music']);
        $root = $library->roots()->create([
            'name' => 'Primary HDD',
            'path' => 'D:\\Music',
            'path_hash' => hash('sha256', 'd:\\music'),
        ]);
        $this->assertSame(['cover.jpg'], $root->fresh()->cover_image_paths);
        $album = Album::create([
            'library_root_id' => $root->id,
            'title' => 'Album',
            'relative_path' => 'Artist\\Album',
            'relative_path_hash' => hash('sha256', 'artist\\album'),
        ]);
        $attributes = [
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => 'Artist\\Album\\Track.flac',
            'relative_path_hash' => hash('sha256', 'artist\\album\\track.flac'),
            'file_size' => 100,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ];

        MediaFile::create($attributes);

        $this->expectException(QueryException::class);
        MediaFile::create($attributes);
    }

    public function test_one_library_can_span_multiple_roots(): void
    {
        $library = Library::create(['name' => 'Distributed Music']);

        $library->roots()->createMany([
            [
                'name' => 'HDD One',
                'path' => 'D:\\Music',
                'path_hash' => hash('sha256', 'd:\\music'),
            ],
            [
                'name' => 'HDD Two',
                'path' => 'E:\\Music',
                'path_hash' => hash('sha256', 'e:\\music'),
            ],
            [
                'name' => 'Archive',
                'path' => 'F:\\Music Archive',
                'path_hash' => hash('sha256', 'f:\\music archive'),
            ],
        ]);

        $this->assertCount(3, $library->fresh()->roots);
        $this->assertSame(
            ['D:\\Music', 'E:\\Music', 'F:\\Music Archive'],
            $library->roots()->orderBy('id')->pluck('path')->all(),
        );
    }

    public function test_artist_letter_buckets_support_a_to_z_and_hash(): void
    {
        Artist::insert([
            [
                'name' => 'ABBA',
                'sort_name' => 'ABBA',
                'browse_initial' => 'A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Bjoerk',
                'sort_name' => 'Bjoerk',
                'browse_initial' => 'B',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '2 Unlimited',
                'sort_name' => '2 Unlimited',
                'browse_initial' => '#',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame(['ABBA'], Artist::where('browse_initial', 'A')->pluck('name')->all());
        $this->assertSame(['2 Unlimited'], Artist::where('browse_initial', '#')->pluck('name')->all());
    }

    public function test_postgresql_search_and_filter_indexes_exist(): void
    {
        $indexes = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->whereIn('indexname', [
                'artists_browse_index',
                'artists_name_ci_unique',
                'artists_name_trgm_index',
                'albums_title_trgm_index',
                'albums_original_release_year_index',
                'albums_artist_year_title_index',
                'genres_name_trgm_index',
                'tracks_title_trgm_index',
                'tracks_album_disc_track_id_index',
                'genres_name_ci_unique',
                'genre_track_pkey',
                'genre_track_track_id_index',
                'media_files_root_last_seen_index',
                'media_files_root_id_index',
                'scan_runs_root_status_id_index',
                'scan_runs_root_status_updated_index',
                'scan_issues_run_severity_id_index',
                'track_play_events_counted_recent_index',
                'track_play_statistics_ranking_index',
            ])
            ->pluck('indexdef', 'indexname');

        $this->assertCount(19, $indexes);
        $this->assertStringContainsString('gin_trgm_ops', $indexes['artists_name_trgm_index']);
        $this->assertStringContainsString('gin_trgm_ops', $indexes['albums_title_trgm_index']);
        $this->assertStringContainsString('gin_trgm_ops', $indexes['tracks_title_trgm_index']);
        $this->assertStringContainsString('browse_initial', $indexes['artists_browse_index']);
        $this->assertStringContainsString('original_release_year', $indexes['albums_artist_year_title_index']);
        $this->assertStringContainsString('lower((name)::text)', $indexes['genres_name_ci_unique']);
        $this->assertStringContainsString('lower((name)::text)', $indexes['artists_name_ci_unique']);
        $this->assertStringContainsString('last_seen_at', $indexes['media_files_root_last_seen_index']);
        $this->assertStringContainsString('album_id, disc_number, track_number, id', $indexes['tracks_album_disc_track_id_index']);
        $this->assertStringContainsString('WHERE (counted = true)', $indexes['track_play_events_counted_recent_index']);
        $this->assertStringContainsString('WHERE (play_count > 0)', $indexes['track_play_statistics_ranking_index']);
    }

    public function test_artist_browse_initial_rejects_values_outside_a_to_z_and_hash(): void
    {
        $this->expectException(QueryException::class);

        Artist::create([
            'name' => 'Invalid Bucket',
            'sort_name' => 'Invalid Bucket',
            'browse_initial' => '1',
        ]);
    }

    public function test_genres_are_unique_ignoring_case(): void
    {
        Genre::create(['name' => 'Electronic']);

        $this->expectException(QueryException::class);
        Genre::create(['name' => 'electronic']);
    }

    public function test_artists_are_unique_ignoring_case(): void
    {
        Artist::create([
            'name' => 'Bjoerk',
            'sort_name' => 'Bjoerk',
            'browse_initial' => 'B',
        ]);

        $this->expectException(QueryException::class);
        Artist::create([
            'name' => 'BJOERK',
            'sort_name' => 'BJOERK',
            'browse_initial' => 'B',
        ]);
    }
}
