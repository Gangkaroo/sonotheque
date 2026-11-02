<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            'CREATE INDEX CONCURRENTLY tracks_catalog_relationships_index '
            .'ON tracks (id) INCLUDE (media_file_id, album_id)',
        );
        DB::statement(
            'ALTER TABLE tracks SET ('
            .'autovacuum_vacuum_scale_factor = 0.02, '
            .'autovacuum_analyze_scale_factor = 0.02)',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS tracks_catalog_relationships_index');
        DB::statement(
            'ALTER TABLE tracks RESET ('
            .'autovacuum_vacuum_scale_factor, autovacuum_analyze_scale_factor)',
        );
    }
};
