<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX artists_catalog_name_sort_index '
            .'ON artists ((coalesce(sort_name, name)), name, id)',
        );
        DB::statement(
            'CREATE INDEX albums_catalog_title_sort_index '
            .'ON albums ((coalesce(sort_title, title)), title, id)',
        );
        DB::statement(
            'CREATE INDEX tracks_catalog_title_sort_index '
            .'ON tracks ((coalesce(sort_title, title)), title, id)',
        );

        Schema::table('albums', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'albums_created_id_index');
        });
        Schema::table('tracks', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'tracks_created_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropIndex('tracks_created_id_index');
        });
        Schema::table('albums', function (Blueprint $table): void {
            $table->dropIndex('albums_created_id_index');
        });

        DB::statement('DROP INDEX tracks_catalog_title_sort_index');
        DB::statement('DROP INDEX albums_catalog_title_sort_index');
        DB::statement('DROP INDEX artists_catalog_name_sort_index');
    }
};
