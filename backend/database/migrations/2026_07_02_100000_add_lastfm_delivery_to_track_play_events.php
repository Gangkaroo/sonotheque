<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('track_play_events', function (Blueprint $table) {
            $table->string('lastfm_status', 32)->nullable()->index();
            $table->unsignedSmallInteger('lastfm_attempts')->default(0);
            $table->timestampTz('lastfm_scrobbled_at')->nullable();
            $table->text('lastfm_error')->nullable();
            $table->unsignedSmallInteger('lastfm_ignored_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('track_play_events', function (Blueprint $table) {
            $table->dropColumn([
                'lastfm_status',
                'lastfm_attempts',
                'lastfm_scrobbled_at',
                'lastfm_error',
                'lastfm_ignored_code',
            ]);
        });
    }
};
