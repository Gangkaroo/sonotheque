<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();

            $table->unique('name');
        });

        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['playlist_folder_id', 'name']);
        });

        Schema::create('playlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['playlist_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_items');
        Schema::dropIfExists('playlists');
        Schema::dropIfExists('playlist_folders');
    }
};
