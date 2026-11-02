<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        // Scan updates need frequent vacuuming to keep availability checks index-only.
        DB::statement(
            'ALTER TABLE media_files SET ('
            .'autovacuum_vacuum_scale_factor = 0.02, '
            .'autovacuum_analyze_scale_factor = 0.02)',
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE media_files RESET ('
            .'autovacuum_vacuum_scale_factor, autovacuum_analyze_scale_factor)',
        );
    }
};
