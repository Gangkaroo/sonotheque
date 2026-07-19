<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('audio_similarity_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audio_analysis_profile_id')
                ->constrained('audio_analysis_profiles')
                ->cascadeOnDelete();
            $table->foreignId('source_track_id')->constrained('tracks')->cascadeOnDelete();
            $table->foreignId('candidate_track_id')->constrained('tracks')->cascadeOnDelete();
            $table->string('verdict', 16);
            $table->timestampsTz();

            $table->unique(
                ['audio_analysis_profile_id', 'source_track_id', 'candidate_track_id'],
                'audio_similarity_feedback_identity_unique',
            );
            $table->index(
                ['audio_analysis_profile_id', 'verdict'],
                'audio_similarity_feedback_profile_verdict_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_similarity_feedback');
    }
};
