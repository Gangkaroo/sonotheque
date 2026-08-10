<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->boolean('audio_similarity_personalization_enabled')
                ->default(false)
                ->after('audio_similarity_intensity_influence');
        });

        Schema::create('audio_similarity_personalizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audio_analysis_profile_id')
                ->unique()
                ->constrained('audio_analysis_profiles')
                ->cascadeOnDelete();
            $table->unsignedInteger('feedback_count');
            $table->unsignedInteger('relevant_count');
            $table->unsignedInteger('irrelevant_count');
            $table->jsonb('adjustments');
            $table->jsonb('feature_statistics');
            $table->timestampTz('trained_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_similarity_personalizations');

        Schema::table('application_settings', function (Blueprint $table): void {
            $table->dropColumn('audio_similarity_personalization_enabled');
        });
    }
};
