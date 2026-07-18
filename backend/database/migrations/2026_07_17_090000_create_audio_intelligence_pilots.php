<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->boolean('audio_intelligence_enabled')->default(false);
            $table->unsignedSmallInteger('audio_intelligence_sample_size')->default(200);
        });

        Schema::create('audio_analysis_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32)->default('prepared');
            $table->uuid('selection_seed');
            $table->unsignedSmallInteger('requested_track_count');
            $table->unsignedSmallInteger('selected_track_count')->default(0);
            $table->jsonb('summary')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });

        Schema::create('audio_analysis_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audio_analysis_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('library_root_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('genre_id')->nullable()->constrained()->nullOnDelete();
            $table->char('content_fingerprint', 64);
            $table->unsignedSmallInteger('content_fingerprint_version');
            $table->unsignedSmallInteger('position');
            $table->string('status', 32)->default('selected');
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['audio_analysis_run_id', 'track_id'],
                'audio_analysis_run_track_unique',
            );
            $table->index(
                ['audio_analysis_run_id', 'status'],
                'audio_analysis_run_status_index',
            );
            $table->index(['track_id', 'content_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_analysis_run_items');
        Schema::dropIfExists('audio_analysis_runs');

        Schema::table('application_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'audio_intelligence_enabled',
                'audio_intelligence_sample_size',
            ]);
        });
    }
};
