<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_root_id')->constrained()->cascadeOnDelete();
            $table->foreignId('primary_artist_id')->nullable()->constrained('artists')->nullOnDelete();
            $table->foreignId('artwork_id')->nullable()->constrained('artwork')->nullOnDelete();
            $table->string('title', 512);
            $table->string('sort_title', 512)->nullable();
            $table->text('relative_path');
            $table->char('relative_path_hash', 64);
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedSmallInteger('disc_total')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['library_root_id', 'relative_path_hash']);
            $table->index(['primary_artist_id', 'title']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('albums');
    }
};
