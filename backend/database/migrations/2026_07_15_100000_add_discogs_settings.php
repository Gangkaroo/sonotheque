<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->text('discogs_personal_access_token')->nullable();
            $table->string('discogs_username')->nullable();
            $table->bigInteger('discogs_user_id')->nullable();
            $table->timestampTz('discogs_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'discogs_personal_access_token',
                'discogs_username',
                'discogs_user_id',
                'discogs_connected_at',
            ]);
        });
    }
};
