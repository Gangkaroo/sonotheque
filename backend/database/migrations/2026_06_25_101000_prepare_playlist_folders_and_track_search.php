<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('playlist_folders', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('playlist_folders')
                ->nullOnDelete();
        });

        Schema::table('playlist_folders', function (Blueprint $table) {
            $table->dropUnique('playlist_folders_name_unique');
        });

        DB::statement('CREATE UNIQUE INDEX playlist_folders_root_name_unique ON playlist_folders (name) WHERE parent_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX playlist_folders_parent_name_unique ON playlist_folders (parent_id, name) WHERE parent_id IS NOT NULL');
        DB::statement('CREATE INDEX tracks_title_trgm_index ON tracks USING gin (title gin_trgm_ops)');
        DB::statement('CREATE INDEX tracks_sort_title_trgm_index ON tracks USING gin (sort_title gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tracks_sort_title_trgm_index');
        DB::statement('DROP INDEX IF EXISTS tracks_title_trgm_index');
        DB::statement('DROP INDEX IF EXISTS playlist_folders_parent_name_unique');
        DB::statement('DROP INDEX IF EXISTS playlist_folders_root_name_unique');

        Schema::table('playlist_folders', function (Blueprint $table) {
            $table->unique('name');
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
