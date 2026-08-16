<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement(
            "CREATE INDEX media_files_available_catalog_index ON media_files (id, library_root_id) WHERE status = 'available'",
        );
        DB::statement('CREATE INDEX musicians_name_trgm_index ON musicians USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS musicians_name_trgm_index');
        DB::statement('DROP INDEX IF EXISTS media_files_available_catalog_index');
    }
};
