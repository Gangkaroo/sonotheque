<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->boolean('audio_similarity_reranking_enabled')
                ->default(false)
                ->after('audio_intelligence_accelerator');
            $table->unsignedSmallInteger('audio_similarity_tempo_influence')
                ->default(5)
                ->after('audio_similarity_reranking_enabled');
            $table->unsignedSmallInteger('audio_similarity_key_influence')
                ->default(3)
                ->after('audio_similarity_tempo_influence');
            $table->unsignedSmallInteger('audio_similarity_intensity_influence')
                ->default(4)
                ->after('audio_similarity_key_influence');
        });
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'audio_similarity_reranking_enabled',
                'audio_similarity_tempo_influence',
                'audio_similarity_key_influence',
                'audio_similarity_intensity_influence',
            ]);
        });
    }
};
