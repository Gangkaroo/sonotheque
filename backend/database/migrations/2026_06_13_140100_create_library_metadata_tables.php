<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 512);
            $table->string('sort_name', 512)->nullable();
            $table->timestampsTz();

            $table->index('name');
            $table->index('sort_name');
        });

        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestampsTz();
        });

        Schema::create('artwork', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 32);
            $table->text('source_relative_path')->nullable();
            $table->text('cache_path');
            $table->text('thumbnail_path');
            $table->string('mime_type', 128);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('checksum', 64)->unique();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork');
        Schema::dropIfExists('genres');
        Schema::dropIfExists('artists');
    }
};
