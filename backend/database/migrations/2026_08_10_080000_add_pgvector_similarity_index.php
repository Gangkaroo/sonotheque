<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement(<<<'SQL'
            CREATE TABLE audio_analysis_vectors (
                audio_analysis_artifact_id BIGINT PRIMARY KEY
                    REFERENCES audio_analysis_artifacts (id) ON DELETE CASCADE,
                audio_analysis_profile_id BIGINT NOT NULL
                    REFERENCES audio_analysis_profiles (id) ON DELETE RESTRICT,
                embedding VECTOR(1280) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX audio_analysis_vectors_profile_index
            ON audio_analysis_vectors (audio_analysis_profile_id)
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO audio_analysis_vectors (
                audio_analysis_artifact_id,
                audio_analysis_profile_id,
                embedding,
                created_at,
                updated_at
            )
            SELECT
                artifacts.id,
                artifacts.audio_analysis_profile_id,
                artifacts.embedding::text::vector(1280),
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM audio_analysis_artifacts AS artifacts
            INNER JOIN audio_analysis_profiles AS profiles
                ON profiles.id = artifacts.audio_analysis_profile_id
            WHERE profiles.embedding_dimensions = 1280
                AND artifacts.embedding IS NOT NULL
                AND jsonb_typeof(artifacts.embedding) = 'array'
                AND jsonb_array_length(artifacts.embedding) = 1280
            ON CONFLICT (audio_analysis_artifact_id) DO NOTHING
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX audio_analysis_vectors_embedding_hnsw_index
            ON audio_analysis_vectors
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 100)
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audio_analysis_vectors');
    }
};
