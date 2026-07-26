<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->string('playlist_export_format', 8)
                ->default('m3u8')
                ->after('audio_intelligence_validation_sample_size');
        });

        Schema::create('playlist_export_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('path');
            $table->char('path_hash', 64)->unique();
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX playlist_export_locations_single_default '
                .'ON playlist_export_locations (is_default) WHERE is_default = true',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_export_locations');

        Schema::table('application_settings', function (Blueprint $table): void {
            $table->dropColumn('playlist_export_format');
        });
    }
};
