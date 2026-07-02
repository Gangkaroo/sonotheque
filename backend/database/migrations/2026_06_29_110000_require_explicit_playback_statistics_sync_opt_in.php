<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::table('application_settings')
            ->where('export_play_statistics_to_tags', false)
            ->update(['import_play_statistics_from_tags' => false]);
    }

    public function down(): void
    {
    }
};
