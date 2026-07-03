<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX artists_name_ci_unique ON artists (LOWER(name))');

        Schema::table('media_files', function (Blueprint $table): void {
            $table->index(['library_root_id', 'last_seen_at'], 'media_files_root_last_seen_index');
            $table->index(['library_root_id', 'id'], 'media_files_root_id_index');
            $table->dropIndex('media_files_modified_at_index');
        });

        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropIndex('tracks_album_id_disc_number_track_number_index');
            $table->dropIndex('tracks_title_index');
            $table->index(
                ['album_id', 'disc_number', 'track_number', 'id'],
                'tracks_album_disc_track_id_index',
            );
        });

        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->dropIndex('scan_runs_library_root_id_status_index');
            $table->dropIndex('scan_runs_status_created_at_index');
            $table->index(['created_at', 'id'], 'scan_runs_created_id_index');
            $table->index(['library_root_id', 'created_at', 'id'], 'scan_runs_root_created_id_index');
            $table->index(['library_root_id', 'status', 'id'], 'scan_runs_root_status_id_index');
            $table->index(
                ['library_root_id', 'status', 'updated_at'],
                'scan_runs_root_status_updated_index',
            );
        });

        Schema::table('track_play_events', function (Blueprint $table): void {
            $table->index('media_file_id', 'track_play_events_media_file_index');
        });
        DB::statement(
            'CREATE INDEX track_play_events_counted_recent_index '
            .'ON track_play_events (played_at DESC, id DESC) WHERE counted = true',
        );
        DB::statement(
            'CREATE INDEX track_play_statistics_ranking_index '
            .'ON track_play_statistics (play_count DESC, last_played_at DESC, track_id) '
            .'WHERE play_count > 0',
        );

        Schema::table('playlist_items', function (Blueprint $table): void {
            $table->index('track_id', 'playlist_items_track_index');
        });
        Schema::table('metadata_edit_jobs', function (Blueprint $table): void {
            $table->index('album_id', 'metadata_edit_jobs_album_index');
            $table->index('media_file_id', 'metadata_edit_jobs_media_file_index');
        });
        Schema::table('metadata_edit_items', function (Blueprint $table): void {
            $table->index('track_id', 'metadata_edit_items_track_index');
            $table->index('media_file_id', 'metadata_edit_items_media_file_index');
        });
        Schema::table('metadata_backups', function (Blueprint $table): void {
            $table->index('media_file_id', 'metadata_backups_media_file_index');
        });

        DB::statement('DROP INDEX artists_sort_name_trgm_index');
        DB::statement('DROP INDEX albums_sort_title_trgm_index');
        DB::statement('DROP INDEX tracks_sort_title_trgm_index');
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX artists_sort_name_trgm_index ON artists USING gin (sort_name gin_trgm_ops)');
        DB::statement('CREATE INDEX albums_sort_title_trgm_index ON albums USING gin (sort_title gin_trgm_ops)');
        DB::statement('CREATE INDEX tracks_sort_title_trgm_index ON tracks USING gin (sort_title gin_trgm_ops)');

        Schema::table('metadata_backups', function (Blueprint $table): void {
            $table->dropIndex('metadata_backups_media_file_index');
        });
        Schema::table('metadata_edit_items', function (Blueprint $table): void {
            $table->dropIndex('metadata_edit_items_media_file_index');
            $table->dropIndex('metadata_edit_items_track_index');
        });
        Schema::table('metadata_edit_jobs', function (Blueprint $table): void {
            $table->dropIndex('metadata_edit_jobs_media_file_index');
            $table->dropIndex('metadata_edit_jobs_album_index');
        });
        Schema::table('playlist_items', function (Blueprint $table): void {
            $table->dropIndex('playlist_items_track_index');
        });

        DB::statement('DROP INDEX track_play_statistics_ranking_index');
        DB::statement('DROP INDEX track_play_events_counted_recent_index');
        Schema::table('track_play_events', function (Blueprint $table): void {
            $table->dropIndex('track_play_events_media_file_index');
        });

        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->dropIndex('scan_runs_root_status_updated_index');
            $table->dropIndex('scan_runs_root_status_id_index');
            $table->dropIndex('scan_runs_root_created_id_index');
            $table->dropIndex('scan_runs_created_id_index');
            $table->index(['status', 'created_at'], 'scan_runs_status_created_at_index');
            $table->index(['library_root_id', 'status'], 'scan_runs_library_root_id_status_index');
        });

        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropIndex('tracks_album_disc_track_id_index');
            $table->index('title', 'tracks_title_index');
            $table->index(
                ['album_id', 'disc_number', 'track_number'],
                'tracks_album_id_disc_number_track_number_index',
            );
        });

        Schema::table('media_files', function (Blueprint $table): void {
            $table->index('modified_at', 'media_files_modified_at_index');
            $table->dropIndex('media_files_root_id_index');
            $table->dropIndex('media_files_root_last_seen_index');
        });

        DB::statement('DROP INDEX artists_name_ci_unique');
    }
};
