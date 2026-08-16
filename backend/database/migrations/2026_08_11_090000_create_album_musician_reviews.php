<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('album_musician_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('lookup_version');
            $table->string('decision', 32);
            $table->timestampTz('reviewed_at');
            $table->timestampsTz();

            $table->unique(
                ['album_id', 'lookup_version'],
                'album_musician_reviews_album_version_unique',
            );
            $table->index(
                ['lookup_version', 'decision'],
                'album_musician_reviews_version_decision_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_musician_reviews');
    }
};
