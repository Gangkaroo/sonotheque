<?php

namespace App\Music\Intelligence;

use App\Enums\MediaFileStatus;
use App\Models\AudioAnalysisArtifact;
use App\Models\AudioAnalysisProfile;
use Illuminate\Support\Facades\DB;
use JsonException;

class AudioVectorIndex
{
    public const DIMENSIONS = 1280;

    private const SEARCH_CANDIDATE_POOL = 500;

    public function __construct(
        private readonly AudioAnalysisProfileSelector $profileSelector,
    ) {
    }

    /**
     * @param  list<float>  $embedding
     * @throws JsonException
     */
    public function synchronize(AudioAnalysisArtifact $artifact, array $embedding): bool
    {
        $profile = $artifact->profile()->first(['id', 'embedding_dimensions']);
        if ($profile === null
            || $profile->embedding_dimensions !== self::DIMENSIONS
            || count($embedding) !== self::DIMENSIONS
            || collect($embedding)->contains(
                fn (mixed $value): bool => ! is_numeric($value) || ! is_finite((float) $value),
            )) {
            return false;
        }

        $vector = json_encode(
            array_map(static fn (mixed $value): float => (float) $value, $embedding),
            JSON_THROW_ON_ERROR,
        );

        DB::statement(<<<'SQL'
            INSERT INTO audio_analysis_vectors (
                audio_analysis_artifact_id,
                audio_analysis_profile_id,
                embedding,
                created_at,
                updated_at
            )
            VALUES (?, ?, ?::vector(1280), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (audio_analysis_artifact_id) DO UPDATE SET
                audio_analysis_profile_id = EXCLUDED.audio_analysis_profile_id,
                embedding = EXCLUDED.embedding,
                updated_at = CURRENT_TIMESTAMP
            SQL, [$artifact->id, $profile->id, $vector]);

        return true;
    }

    public function supports(AudioAnalysisProfile $profile): bool
    {
        return $profile->embedding_dimensions === self::DIMENSIONS;
    }

    /**
     * @return array{status: string, dimensions: int, indexedArtifactCount: int, eligibleArtifactCount: int}
     */
    public function status(): array
    {
        $profile = $this->profileSelector->current();
        if ($profile === null) {
            return [
                'status' => 'empty',
                'dimensions' => self::DIMENSIONS,
                'indexedArtifactCount' => 0,
                'eligibleArtifactCount' => 0,
            ];
        }

        if (! $this->supports($profile)) {
            return [
                'status' => 'unsupported',
                'dimensions' => self::DIMENSIONS,
                'indexedArtifactCount' => 0,
                'eligibleArtifactCount' => 0,
            ];
        }

        $eligibleArtifactCount = $profile->artifacts()->whereNotNull('embedding')->count();
        $indexedArtifactCount = DB::table('audio_analysis_vectors')
            ->where('audio_analysis_profile_id', $profile->id)
            ->count();

        return [
            'status' => match (true) {
                $eligibleArtifactCount === 0 => 'empty',
                $indexedArtifactCount >= $eligibleArtifactCount => 'ready',
                default => 'incomplete',
            },
            'dimensions' => self::DIMENSIONS,
            'indexedArtifactCount' => $indexedArtifactCount,
            'eligibleArtifactCount' => $eligibleArtifactCount,
        ];
    }

    /**
     * @param  list<int>  $sourceArtistIds
     * @return null|array{candidateCount: int, matches: list<array{trackId: int, similarity: float}>}
     */
    public function nearestTracks(
        AudioAnalysisProfile $profile,
        int $sourceArtifactId,
        int $sourceTrackId,
        ?int $sourceAlbumId,
        array $sourceArtistIds,
        int $limit,
        bool $excludeSameAlbum,
        bool $excludeSameArtist,
        ?int $libraryRootId = null,
    ): ?array {
        if (! $this->supports($profile)) {
            return null;
        }

        $source = DB::selectOne(<<<'SQL'
            SELECT embedding::text AS embedding
            FROM audio_analysis_vectors
            WHERE audio_analysis_artifact_id = ?
                AND audio_analysis_profile_id = ?
            SQL, [$sourceArtifactId, $profile->id]);
        if (! is_object($source) || ! is_string($source->embedding ?? null)) {
            return null;
        }

        [$filters, $filterBindings] = $this->candidateFilters(
            $sourceTrackId,
            $sourceAlbumId,
            $sourceArtistIds,
            $excludeSameAlbum,
            $excludeSameArtist,
            $libraryRootId,
        );
        $latestItems = <<<'SQL'
            SELECT DISTINCT ON (items.track_id)
                items.track_id,
                items.audio_analysis_artifact_id
            FROM audio_analysis_run_items AS items
            INNER JOIN audio_analysis_artifacts AS artifacts
                ON artifacts.id = items.audio_analysis_artifact_id
            WHERE artifacts.audio_analysis_profile_id = ?
                AND items.status IN ('completed', 'reused')
                AND items.track_id IS NOT NULL
            ORDER BY items.track_id, items.id DESC
            SQL;
        $countFrom = <<<SQL
            FROM latest_items AS latest
            INNER JOIN audio_analysis_vectors AS vectors
                ON vectors.audio_analysis_artifact_id = latest.audio_analysis_artifact_id
                AND vectors.audio_analysis_profile_id = ?
            INNER JOIN tracks ON tracks.id = latest.track_id
            INNER JOIN media_files ON media_files.id = tracks.media_file_id
            INNER JOIN library_roots ON library_roots.id = media_files.library_root_id
            LEFT JOIN albums ON albums.id = tracks.album_id
            WHERE {$filters}
            SQL;
        $searchFrom = <<<SQL
            FROM nearest_vectors
            INNER JOIN latest_items AS latest
                ON latest.audio_analysis_artifact_id = nearest_vectors.audio_analysis_artifact_id
            INNER JOIN tracks ON tracks.id = latest.track_id
            INNER JOIN media_files ON media_files.id = tracks.media_file_id
            INNER JOIN library_roots ON library_roots.id = media_files.library_root_id
            LEFT JOIN albums ON albums.id = tracks.album_id
            WHERE {$filters}
            SQL;

        return DB::transaction(function () use (
            $countFrom,
            $filterBindings,
            $latestItems,
            $limit,
            $profile,
            $searchFrom,
            $source,
        ): array {
            DB::statement('SET LOCAL hnsw.ef_search = 100');
            DB::statement('SET LOCAL hnsw.iterative_scan = strict_order');

            $count = DB::selectOne(
                "WITH latest_items AS MATERIALIZED ({$latestItems}) "
                    ."SELECT COUNT(*) AS aggregate {$countFrom}",
                [$profile->id, $profile->id, ...$filterBindings],
            );
            $rows = DB::select(
                "WITH latest_items AS MATERIALIZED ({$latestItems}), "
                    .'nearest_vectors AS MATERIALIZED ('
                    .'SELECT audio_analysis_artifact_id, '
                    .'embedding <=> ?::vector(1280) AS distance '
                    .'FROM audio_analysis_vectors '
                    .'WHERE audio_analysis_profile_id = ? '
                    .'ORDER BY embedding <=> ?::vector(1280) '
                    .'LIMIT '.self::SEARCH_CANDIDATE_POOL
                    .') '
                    .'SELECT latest.track_id, 1 - nearest_vectors.distance AS similarity '
                    .$searchFrom
                    .' ORDER BY nearest_vectors.distance '
                    ."LIMIT {$limit}",
                [
                    $profile->id,
                    $source->embedding,
                    $profile->id,
                    $source->embedding,
                    ...$filterBindings,
                ],
            );

            return [
                'candidateCount' => (int) ($count?->aggregate ?? 0),
                'matches' => collect($rows)
                    ->map(fn (object $row): array => [
                        'trackId' => (int) $row->track_id,
                        'similarity' => (float) $row->similarity,
                    ])
                    ->all(),
            ];
        });
    }

    /**
     * @param  list<int>  $sourceArtistIds
     * @return array{string, list<int|string>}
     */
    private function candidateFilters(
        int $sourceTrackId,
        ?int $sourceAlbumId,
        array $sourceArtistIds,
        bool $excludeSameAlbum,
        bool $excludeSameArtist,
        ?int $libraryRootId,
    ): array {
        $filters = [
            'latest.track_id <> ?',
            'media_files.status = ?',
            'library_roots.enabled = true',
        ];
        $bindings = [$sourceTrackId, MediaFileStatus::Available->value];

        if ($libraryRootId !== null) {
            $filters[] = 'library_roots.id = ?';
            $bindings[] = $libraryRootId;
        }

        if ($excludeSameAlbum && $sourceAlbumId !== null) {
            $filters[] = 'tracks.album_id IS DISTINCT FROM ?';
            $bindings[] = $sourceAlbumId;
        }

        if ($excludeSameArtist && $sourceArtistIds !== []) {
            $artistIds = '{'.implode(',', array_map('intval', $sourceArtistIds)).'}';
            $filters[] = <<<'SQL'
                NOT EXISTS (
                    SELECT 1
                    FROM artist_track AS candidate_artists
                    WHERE candidate_artists.track_id = latest.track_id
                        AND candidate_artists.artist_id = ANY (?::bigint[])
                )
                SQL;
            $filters[] = <<<'SQL'
                NOT (
                    NOT EXISTS (
                        SELECT 1
                        FROM artist_track AS any_candidate_artist
                        WHERE any_candidate_artist.track_id = latest.track_id
                    )
                    AND albums.primary_artist_id = ANY (?::bigint[])
                )
                SQL;
            $bindings[] = $artistIds;
            $bindings[] = $artistIds;
        }

        return [implode(' AND ', $filters), $bindings];
    }
}
