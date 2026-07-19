<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE audio_analysis_runs AS run
            SET summary = COALESCE(run.summary, '{}'::jsonb)
                || jsonb_build_object('selectedArtistCount', coverage.artist_count)
            FROM (
                SELECT
                    items.audio_analysis_run_id,
                    COUNT(
                        DISTINCT COALESCE(artist_track.artist_id, albums.primary_artist_id)
                    )::int AS artist_count
                FROM audio_analysis_run_items AS items
                JOIN tracks ON tracks.id = items.track_id
                JOIN albums ON albums.id = tracks.album_id
                LEFT JOIN artist_track ON artist_track.track_id = tracks.id
                WHERE items.status NOT IN ('pending_fingerprint', 'not_selected')
                GROUP BY items.audio_analysis_run_id
            ) AS coverage
            WHERE coverage.audio_analysis_run_id = run.id
            SQL);
    }

    public function down(): void
    {
        // Derived summary data does not need destructive rollback.
    }
};
