<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::table('artists', function (Blueprint $table) {
            $table->char('browse_initial', 1)->default('#')->after('sort_name');
            $table->index(['browse_initial', 'sort_name', 'id'], 'artists_browse_index');
        });
        DB::statement("ALTER TABLE artists ADD CONSTRAINT artists_browse_initial_check CHECK (browse_initial ~ '^[A-Z#]$')");

        Schema::table('albums', function (Blueprint $table) {
            $table->renameColumn('year', 'original_release_year');
            $table->index('original_release_year');
            $table->index(
                ['primary_artist_id', 'original_release_year', 'title'],
                'albums_artist_year_title_index',
            );
        });

        Schema::table('library_roots', function (Blueprint $table) {
            $table->index(['library_id', 'enabled'], 'library_roots_library_enabled_index');
        });

        DB::statement('CREATE INDEX artists_name_trgm_index ON artists USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX artists_sort_name_trgm_index ON artists USING gin (sort_name gin_trgm_ops)');
        DB::statement('CREATE INDEX albums_title_trgm_index ON albums USING gin (title gin_trgm_ops)');
        DB::statement('CREATE INDEX albums_sort_title_trgm_index ON albums USING gin (sort_title gin_trgm_ops)');
        DB::statement('CREATE INDEX genres_name_trgm_index ON genres USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS genres_name_trgm_index');
        DB::statement('DROP INDEX IF EXISTS albums_sort_title_trgm_index');
        DB::statement('DROP INDEX IF EXISTS albums_title_trgm_index');
        DB::statement('DROP INDEX IF EXISTS artists_sort_name_trgm_index');
        DB::statement('DROP INDEX IF EXISTS artists_name_trgm_index');

        Schema::table('library_roots', function (Blueprint $table) {
            $table->dropIndex('library_roots_library_enabled_index');
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex('albums_artist_year_title_index');
            $table->dropIndex(['original_release_year']);
            $table->renameColumn('original_release_year', 'year');
        });

        DB::statement('ALTER TABLE artists DROP CONSTRAINT IF EXISTS artists_browse_initial_check');
        Schema::table('artists', function (Blueprint $table) {
            $table->dropIndex('artists_browse_index');
            $table->dropColumn('browse_initial');
        });
    }
};
