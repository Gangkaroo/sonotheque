<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('manual_album_musician_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('musician_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('credited_as')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->boolean('is_additional')->default(false);
            $table->timestampsTz();

            $table->index(['album_id', 'musician_id'], 'manual_musician_credits_album_musician_index');
            $table->index(['musician_id', 'album_id'], 'manual_musician_credits_musician_album_index');
            $table->index('role', 'manual_musician_credits_role_index');
        });

        Schema::create('manual_album_musician_credit_track', function (Blueprint $table): void {
            $table->foreignId('manual_album_musician_credit_id')
                ->constrained('manual_album_musician_credits')
                ->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();

            $table->primary(
                ['manual_album_musician_credit_id', 'track_id'],
                'manual_musician_credit_track_primary',
            );
            $table->index('track_id', 'manual_musician_credit_track_track_index');
        });

        Schema::create('album_musician_credit_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->char('source_credit_key', 64);
            $table->timestampsTz();

            $table->unique(
                ['album_id', 'provider', 'source_credit_key'],
                'album_musician_credit_suppression_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_musician_credit_suppressions');
        Schema::dropIfExists('manual_album_musician_credit_track');
        Schema::dropIfExists('manual_album_musician_credits');
    }
};
