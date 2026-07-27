<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->index(
                ['library_root_id', 'play_statistics_import_version', 'id'],
                'media_files_play_statistics_import_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropIndex('media_files_play_statistics_import_index');
        });
    }
};
