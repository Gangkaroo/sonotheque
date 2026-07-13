<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            WITH global_counts AS (
                SELECT DISTINCT
                    statistics.id,
                    statistics.play_count,
                    (statistics.source_metadata #>> '{file_tags,source_fields,play_count}')::bigint
                        AS imported_count,
                    CASE
                        WHEN media_files.raw_metadata #>> '{id3v2,comments,text,play_count}' ~ '^[0-9]+$'
                        THEN (media_files.raw_metadata #>> '{id3v2,comments,text,play_count}')::bigint
                        ELSE 0
                    END AS personal_count
                FROM track_play_statistics AS statistics
                INNER JOIN tracks ON tracks.id = statistics.track_id
                INNER JOIN media_files ON media_files.id = tracks.media_file_id
                WHERE statistics.source_metadata #>> '{file_tags,source_fields,play_count}' ~ '^[0-9]+$'
                    AND EXISTS (
                        SELECT 1
                        FROM jsonb_array_elements(
                            COALESCE(media_files.raw_metadata->'id3v2'->'TXXX', '[]'::jsonb)
                        ) AS frame
                        WHERE frame->>'description' = 'PLAY COUNT'
                    )
                    AND EXISTS (
                        SELECT 1
                        FROM jsonb_array_elements(
                            COALESCE(media_files.raw_metadata->'id3v2'->'TXXX', '[]'::jsonb)
                        ) AS frame
                        WHERE upper(frame->>'description') IN ('LISTENERS', 'LASTFMPLAYCOUNT')
                    )
            )
            UPDATE track_play_statistics AS statistics
            SET
                play_count = GREATEST(
                    statistics.play_count::bigint - global_counts.imported_count,
                    0
                )::integer + global_counts.personal_count::integer,
                source_metadata = CASE
                    WHEN global_counts.personal_count > 0 THEN jsonb_set(
                        jsonb_set(
                            jsonb_set(
                                statistics.source_metadata #- '{file_tags,source_fields,play_count}',
                                '{file_tags,play_count}',
                                to_jsonb(global_counts.personal_count::integer),
                                true
                            ),
                            '{file_tags,source_fields,ignored_global_play_count}',
                            to_jsonb(global_counts.imported_count::text),
                            true
                        ),
                        '{file_tags,source_fields,play_count}',
                        to_jsonb(global_counts.personal_count::text),
                        true
                    )
                    ELSE jsonb_set(
                        jsonb_set(
                            statistics.source_metadata #- '{file_tags,source_fields,play_count}',
                            '{file_tags,play_count}',
                            'null'::jsonb,
                            true
                        ),
                        '{file_tags,source_fields,ignored_global_play_count}',
                        to_jsonb(global_counts.imported_count::text),
                        true
                    )
                END,
                updated_at = CURRENT_TIMESTAMP
            FROM global_counts
            WHERE statistics.id = global_counts.id
            SQL);
    }

    public function down(): void
    {
        // Ignored global popularity values cannot be restored as personal plays.
    }
};
