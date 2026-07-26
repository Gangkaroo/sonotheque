<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->timestampTz('playlist_export_sync_pending_at')
                ->nullable()
                ->after('playlist_export_synced_at')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropColumn('playlist_export_sync_pending_at');
        });
    }
};
