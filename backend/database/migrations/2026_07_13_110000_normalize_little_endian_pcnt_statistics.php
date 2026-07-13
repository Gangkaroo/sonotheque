<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            WITH candidates AS (
                SELECT
                    id,
                    play_count,
                    (source_metadata #>> '{file_tags,source_fields,pcnt}')::bigint AS raw_count
                FROM track_play_statistics
                WHERE source_metadata #>> '{file_tags,source_fields,pcnt}' ~ '^[0-9]+$'
            ),
            normalized AS (
                SELECT
                    id,
                    play_count,
                    raw_count,
                    ((raw_count & 255) << 24)
                        | ((raw_count & 65280) << 8)
                        | ((raw_count & 16711680) >> 8)
                        | ((raw_count & 4278190080) >> 24) AS normalized_count
                FROM candidates
                WHERE raw_count BETWEEN 1000001 AND 2147483647
            )
            UPDATE track_play_statistics AS statistics
            SET
                play_count = LEAST(
                    2147483647,
                    normalized.normalized_count
                        + GREATEST(statistics.play_count::bigint - normalized.raw_count, 0)
                )::integer,
                source_metadata = jsonb_set(
                    statistics.source_metadata,
                    '{file_tags,play_count}',
                    to_jsonb(normalized.normalized_count::integer),
                    true
                ),
                updated_at = CURRENT_TIMESTAMP
            FROM normalized
            WHERE statistics.id = normalized.id
                AND normalized.normalized_count BETWEEN 0 AND 1000000
                AND normalized.normalized_count < normalized.raw_count
            SQL);
    }

    public function down(): void
    {
        // Restoring known-invalid counters would corrupt corrected statistics.
    }
};
