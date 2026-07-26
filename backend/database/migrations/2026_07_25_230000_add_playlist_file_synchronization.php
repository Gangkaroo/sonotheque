<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->boolean('synchronize_playlists_to_files')
                ->default(false)
                ->after('playlist_export_format');
        });

        Schema::table('playlists', function (Blueprint $table): void {
            $table->foreignId('playlist_export_location_id')
                ->nullable()
                ->after('description')
                ->constrained()
                ->nullOnDelete();
            $table->text('playlist_export_root_path')->nullable();
            $table->text('playlist_export_relative_path')->nullable();
            $table->timestampTz('playlist_export_synced_at')->nullable();
            $table->text('playlist_export_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('playlist_export_location_id');
            $table->dropColumn([
                'playlist_export_root_path',
                'playlist_export_relative_path',
                'playlist_export_synced_at',
                'playlist_export_sync_error',
            ]);
        });

        Schema::table('application_settings', function (Blueprint $table): void {
            $table->dropColumn('synchronize_playlists_to_files');
        });
    }
};
