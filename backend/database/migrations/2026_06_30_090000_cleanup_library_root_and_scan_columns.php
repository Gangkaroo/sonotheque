<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE library_roots
            SET cover_image_paths = jsonb_build_array(COALESCE(NULLIF(cover_image_path, ''), 'cover.jpg'))
            WHERE cover_image_paths IS NULL OR cover_image_paths = '[]'::jsonb
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE library_roots
            ALTER COLUMN cover_image_paths SET DEFAULT '["cover.jpg"]'::jsonb,
            ALTER COLUMN cover_image_paths SET NOT NULL
        SQL);

        Schema::table('library_roots', function (Blueprint $table) {
            $table->dropColumn('cover_image_path');
        });

        Schema::table('scan_runs', function (Blueprint $table) {
            $table->renameColumn('files_missing', 'files_removed');
        });
    }

    public function down(): void
    {
        Schema::table('scan_runs', function (Blueprint $table) {
            $table->renameColumn('files_removed', 'files_missing');
        });

        Schema::table('library_roots', function (Blueprint $table) {
            $table->string('cover_image_path', 1024)->default('cover.jpg');
        });

        DB::statement(<<<'SQL'
            UPDATE library_roots
            SET cover_image_path = COALESCE(NULLIF(cover_image_paths->>0, ''), 'cover.jpg')
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE library_roots
            ALTER COLUMN cover_image_paths DROP DEFAULT,
            ALTER COLUMN cover_image_paths DROP NOT NULL
        SQL);
    }
};
