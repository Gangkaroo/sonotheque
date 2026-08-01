<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('album_discogs_musician_sources', function (Blueprint $table): void {
            $table->foreignId('album_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('owned_album_copy_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('release_id');
            $table->text('source_url');
            $table->timestampTz('fetched_at');
            $table->timestampsTz();

            $table->index('release_id', 'album_discogs_musician_source_release_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_discogs_musician_sources');
    }
};
