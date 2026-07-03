<?php

namespace App\Music\PlaybackStatistics;

use App\Models\Track;
use Illuminate\Support\Facades\DB;

class PlaybackStatisticsImporter
{
    public function merge(Track $track, ImportedPlayStatistics $imported): bool
    {
        return $this->mergeMany([[
            'trackId' => $track->id,
            'statistics' => $imported,
        ]]) === 1;
    }

    /**
     * @param  list<array{trackId: int, statistics: ImportedPlayStatistics}>  $imports
     */
    public function mergeMany(array $imports): int
    {
        $imports = array_values(array_filter(
            $imports,
            static fn (array $import): bool => $import['statistics']->hasValues(),
        ));
        if ($imports === []) {
            return 0;
        }

        $placeholders = [];
        $bindings = [];
        $now = now();

        foreach ($imports as $import) {
            $statistics = $import['statistics'];
            $placeholders[] = '(?, ?, ?, ?, ?::jsonb, ?, ?)';
            array_push(
                $bindings,
                $import['trackId'],
                $statistics->playCount ?? 0,
                $statistics->firstPlayedAt,
                $statistics->lastPlayedAt,
                json_encode(['file_tags' => $statistics->sourceMetadata()], JSON_THROW_ON_ERROR),
                $now,
                $now,
            );
        }

        $firstPlayedAt = <<<'SQL'
            CASE
                WHEN track_play_statistics.first_played_at IS NULL THEN EXCLUDED.first_played_at
                WHEN EXCLUDED.first_played_at IS NULL THEN track_play_statistics.first_played_at
                ELSE LEAST(track_play_statistics.first_played_at, EXCLUDED.first_played_at)
            END
            SQL;
        $lastPlayedAt = <<<'SQL'
            CASE
                WHEN track_play_statistics.last_played_at IS NULL THEN EXCLUDED.last_played_at
                WHEN EXCLUDED.last_played_at IS NULL THEN track_play_statistics.last_played_at
                ELSE GREATEST(track_play_statistics.last_played_at, EXCLUDED.last_played_at)
            END
            SQL;
        $sql = sprintf(<<<'SQL'
            INSERT INTO track_play_statistics
                (track_id, play_count, first_played_at, last_played_at, source_metadata, created_at, updated_at)
            VALUES %s
            ON CONFLICT (track_id) DO UPDATE SET
                play_count = GREATEST(track_play_statistics.play_count, EXCLUDED.play_count),
                first_played_at = %s,
                last_played_at = %s,
                source_metadata = COALESCE(track_play_statistics.source_metadata, '{}'::jsonb) || EXCLUDED.source_metadata,
                updated_at = EXCLUDED.updated_at
            WHERE track_play_statistics.play_count IS DISTINCT FROM GREATEST(track_play_statistics.play_count, EXCLUDED.play_count)
                OR track_play_statistics.first_played_at IS DISTINCT FROM %s
                OR track_play_statistics.last_played_at IS DISTINCT FROM %s
                OR COALESCE(track_play_statistics.source_metadata->'file_tags', 'null'::jsonb)
                    IS DISTINCT FROM EXCLUDED.source_metadata->'file_tags'
            RETURNING track_id
            SQL, implode(', ', $placeholders), $firstPlayedAt, $lastPlayedAt, $firstPlayedAt, $lastPlayedAt);

        return count(DB::select($sql, $bindings));
    }
}
