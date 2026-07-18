<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('audio_analysis_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('profile_key', 128);
            $table->unsignedSmallInteger('protocol_version');
            $table->string('analyzer_name');
            $table->string('analyzer_version', 128);
            $table->string('analyzer_license', 128);
            $table->string('model_name');
            $table->string('model_version', 128);
            $table->char('model_checksum', 64);
            $table->string('model_license', 128);
            $table->unsignedSmallInteger('embedding_dimensions');
            $table->unsignedInteger('sample_rate');
            $table->jsonb('manifest')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['profile_key', 'analyzer_version', 'model_version', 'model_checksum'],
                'audio_analysis_profile_identity_unique',
            );
        });

        Schema::table('audio_analysis_runs', function (Blueprint $table): void {
            $table->foreignId('audio_analysis_profile_id')
                ->nullable()
                ->after('id')
                ->constrained('audio_analysis_profiles')
                ->nullOnDelete();
        });

        Schema::create('audio_analysis_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audio_analysis_run_item_id')
                ->unique()
                ->constrained('audio_analysis_run_items')
                ->cascadeOnDelete();
            $table->foreignId('audio_analysis_profile_id')
                ->constrained('audio_analysis_profiles')
                ->restrictOnDelete();
            $table->jsonb('features')->nullable();
            $table->jsonb('embedding')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->unsignedSmallInteger('windows_analyzed')->nullable();
            $table->jsonb('hardware')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_analysis_results');

        Schema::table('audio_analysis_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('audio_analysis_profile_id');
        });

        Schema::dropIfExists('audio_analysis_profiles');
    }
};
