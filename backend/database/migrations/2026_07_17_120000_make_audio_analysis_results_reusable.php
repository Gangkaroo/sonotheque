<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('audio_analysis_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audio_analysis_profile_id')
                ->constrained('audio_analysis_profiles')
                ->restrictOnDelete();
            $table->char('content_fingerprint', 64);
            $table->unsignedSmallInteger('content_fingerprint_version');
            $table->jsonb('features')->nullable();
            $table->jsonb('embedding')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->unsignedSmallInteger('windows_analyzed')->nullable();
            $table->jsonb('hardware')->nullable();
            $table->timestampsTz();

            $table->unique(
                [
                    'audio_analysis_profile_id',
                    'content_fingerprint',
                    'content_fingerprint_version',
                ],
                'audio_analysis_artifact_identity_unique',
            );
        });

        Schema::table('audio_analysis_run_items', function (Blueprint $table): void {
            $table->foreignId('audio_analysis_artifact_id')
                ->nullable()
                ->after('genre_id')
                ->constrained('audio_analysis_artifacts')
                ->nullOnDelete();
        });
        Schema::table('audio_analysis_runs', function (Blueprint $table): void {
            $table->timestampTz('heartbeat_at')->nullable()->after('cancel_requested_at');
        });

        DB::statement(<<<'SQL'
            INSERT INTO audio_analysis_artifacts (
                audio_analysis_profile_id,
                content_fingerprint,
                content_fingerprint_version,
                features,
                embedding,
                runtime_ms,
                windows_analyzed,
                hardware,
                created_at,
                updated_at
            )
            SELECT
                result.audio_analysis_profile_id,
                item.content_fingerprint,
                item.content_fingerprint_version,
                result.features,
                result.embedding,
                result.runtime_ms,
                result.windows_analyzed,
                result.hardware,
                result.created_at,
                result.updated_at
            FROM audio_analysis_results AS result
            JOIN audio_analysis_run_items AS item
                ON item.id = result.audio_analysis_run_item_id
            ON CONFLICT (
                audio_analysis_profile_id,
                content_fingerprint,
                content_fingerprint_version
            ) DO NOTHING
            SQL);
        DB::statement(<<<'SQL'
            UPDATE audio_analysis_run_items AS item
            SET audio_analysis_artifact_id = artifact.id
            FROM audio_analysis_results AS result
            JOIN audio_analysis_artifacts AS artifact
                ON artifact.audio_analysis_profile_id = result.audio_analysis_profile_id
            WHERE result.audio_analysis_run_item_id = item.id
                AND artifact.content_fingerprint = item.content_fingerprint
                AND artifact.content_fingerprint_version = item.content_fingerprint_version
            SQL);

        Schema::drop('audio_analysis_results');
    }

    public function down(): void
    {
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

        DB::statement(<<<'SQL'
            INSERT INTO audio_analysis_results (
                audio_analysis_run_item_id,
                audio_analysis_profile_id,
                features,
                embedding,
                runtime_ms,
                windows_analyzed,
                hardware,
                created_at,
                updated_at
            )
            SELECT
                item.id,
                artifact.audio_analysis_profile_id,
                artifact.features,
                artifact.embedding,
                artifact.runtime_ms,
                artifact.windows_analyzed,
                artifact.hardware,
                artifact.created_at,
                artifact.updated_at
            FROM audio_analysis_run_items AS item
            JOIN audio_analysis_artifacts AS artifact
                ON artifact.id = item.audio_analysis_artifact_id
            SQL);

        Schema::table('audio_analysis_run_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('audio_analysis_artifact_id');
        });
        Schema::table('audio_analysis_runs', function (Blueprint $table): void {
            $table->dropColumn('heartbeat_at');
        });
        Schema::drop('audio_analysis_artifacts');
    }
};
