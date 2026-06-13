<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_file_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title', 512);
            $table->string('sort_title', 512)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('track_number')->nullable();
            $table->unsignedSmallInteger('disc_number')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['album_id', 'disc_number', 'track_number']);
            $table->index('title');
        });

        Schema::create('artist_track', function (Blueprint $table) {
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->string('role', 64)->default('primary');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->primary(['artist_id', 'track_id']);
            $table->index(['track_id', 'position']);
        });

        Schema::create('genre_track', function (Blueprint $table) {
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->primary(['genre_id', 'track_id']);
            $table->index('track_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genre_track');
        Schema::dropIfExists('artist_track');
        Schema::dropIfExists('tracks');
    }
};
