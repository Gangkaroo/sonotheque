<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement(
            "CREATE INDEX tracks_title_prefix_search_index ON tracks USING gin (to_tsvector('simple', coalesce(title, '')))",
        );
        DB::statement(
            "CREATE INDEX artists_name_prefix_search_index ON artists USING gin (to_tsvector('simple', coalesce(name, '')))",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS artists_name_prefix_search_index');
        DB::statement('DROP INDEX IF EXISTS tracks_title_prefix_search_index');
    }
};
