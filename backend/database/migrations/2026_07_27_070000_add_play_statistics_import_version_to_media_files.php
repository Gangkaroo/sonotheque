<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->unsignedSmallInteger('play_statistics_import_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropColumn('play_statistics_import_version');
        });
    }
};
