<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('album_musician_enrichments', function (Blueprint $table): void {
            $table->jsonb('related_discogs_release_ids')->nullable();
        });

        Schema::table('album_discogs_musician_sources', function (Blueprint $table): void {
            $table->string('source_type', 32)->default('owned_copy');
            $table->unsignedBigInteger('owned_album_copy_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('album_discogs_musician_sources')
            ->whereNull('owned_album_copy_id')
            ->delete();

        Schema::table('album_discogs_musician_sources', function (Blueprint $table): void {
            $table->unsignedBigInteger('owned_album_copy_id')->nullable(false)->change();
            $table->dropColumn('source_type');
        });

        Schema::table('album_musician_enrichments', function (Blueprint $table): void {
            $table->dropColumn('related_discogs_release_ids');
        });
    }
};
