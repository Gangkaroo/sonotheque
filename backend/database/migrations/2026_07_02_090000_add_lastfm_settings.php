<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('lastfm_scrobbling_enabled')->default(false);
            $table->string('lastfm_api_key', 64)->nullable();
            $table->text('lastfm_api_secret')->nullable();
            $table->text('lastfm_session_key')->nullable();
            $table->string('lastfm_username')->nullable();
            $table->text('lastfm_auth_token')->nullable();
            $table->timestampTz('lastfm_auth_token_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn([
                'lastfm_scrobbling_enabled',
                'lastfm_api_key',
                'lastfm_api_secret',
                'lastfm_session_key',
                'lastfm_username',
                'lastfm_auth_token',
                'lastfm_auth_token_expires_at',
            ]);
        });
    }
};
