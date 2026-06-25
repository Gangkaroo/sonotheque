<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->index('created_at');
        });

        Schema::create('favorite_albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite_albums');
        Schema::dropIfExists('favorite_tracks');
    }
};
