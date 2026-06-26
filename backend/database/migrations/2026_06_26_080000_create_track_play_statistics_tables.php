<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_play_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('play_count')->default(0);
            $table->timestampTz('first_played_at')->nullable();
            $table->timestampTz('last_played_at')->nullable();
            $table->jsonb('source_metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('track_play_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_file_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('played_at');
            $table->unsignedInteger('listened_ms')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('counted')->default(true);
            $table->string('source', 64)->default('app');
            $table->string('context', 64)->nullable();
            $table->timestampsTz();

            $table->index(['track_id', 'played_at']);
            $table->index(['played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('track_play_events');
        Schema::dropIfExists('track_play_statistics');
    }
};
