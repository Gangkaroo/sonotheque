<?php

namespace App\Music\Intelligence;

use App\Models\ApplicationSetting;
use App\Models\AudioAnalysisProfile;
use App\Models\AudioAnalysisRunItem;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\PlaylistOrderSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AudioSimilarityPlaylistOrderer
{
    public const MAXIMUM_ANALYZED_ITEMS = 250;

    private const MAXIMUM_TWO_OPT_PASSES = 8;

    private const IMPROVEMENT_EPSILON = 0.000001;

    public function __construct(
        private readonly AudioVectorIndex $vectorIndex,
        private readonly AudioAnalysisProfileSelector $profileSelector,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(Playlist $playlist): array
    {
        $settings = ApplicationSetting::current();
        $items = $this->orderedItems($playlist);
        $profile = $this->latestProfile();
        $supported = $profile !== null && $this->vectorIndex->supports($profile);
        $artifacts = $supported
            ? $this->artifactsForTracks($profile, $items->pluck('track_id')->unique()->all())
            : collect();
        $analyzedItemIds = $items
            ->filter(fn (PlaylistItem $item): bool => $artifacts->has($item->track_id))
            ->pluck('id')
            ->all();

        return [
            'enabled' => (bool) $settings->audio_intelligence_enabled,
            'available' => $settings->audio_intelligence_enabled
                && $supported
                && count($analyzedItemIds) >= 2
                && count($analyzedItemIds) <= self::MAXIMUM_ANALYZED_ITEMS,
            'maximumAnalyzedItems' => self::MAXIMUM_ANALYZED_ITEMS,
            'profile' => $profile === null ? null : [
                'id' => $profile->id,
                'modelName' => $profile->model_name,
                'modelVersion' => $profile->model_version,
            ],
            'analyzedItemIds' => $analyzedItemIds,
            'unanalyzedItemIds' => $items->pluck('id')->diff($analyzedItemIds)->values()->all(),
            'canUndo' => $this->restorableSnapshot($playlist, $items->pluck('id')->all()) !== null,
        ];
    }

    /** @return array<string, mixed> */
    public function preview(Playlist $playlist, int $openingItemId): array
    {
        $settings = ApplicationSetting::current();
        abort_unless(
            $settings->audio_intelligence_enabled,
            409,
            'Enable audio intelligence before ordering a playlist by similarity.',
        );

        $profile = $this->latestProfile();
        abort_if(
            $profile === null || ! $this->vectorIndex->supports($profile),
            409,
            'No compatible audio similarity profile is available.',
        );

        $items = $this->orderedItems($playlist);
        abort_if($items->count() < 2, 422, 'A playlist needs at least two tracks to be reordered.');
        abort_unless(
            $items->contains(fn (PlaylistItem $item): bool => $item->id === $openingItemId),
            422,
            'The opening track must be part of this playlist.',
        );

        $artifacts = $this->artifactsForTracks($profile, $items->pluck('track_id')->unique()->all());
        $analyzed = $items
            ->filter(fn (PlaylistItem $item): bool => $artifacts->has($item->track_id))
            ->map(fn (PlaylistItem $item): array => [
                'itemId' => $item->id,
                'trackId' => $item->track_id,
                'position' => $item->position,
                'artifactId' => (int) $artifacts->get($item->track_id),
            ])
            ->values();
        $unanalyzed = $items
            ->reject(fn (PlaylistItem $item): bool => $artifacts->has($item->track_id))
            ->values();

        abort_unless(
            $analyzed->contains(fn (array $item): bool => $item['itemId'] === $openingItemId),
            422,
            'The selected opening track has not been analyzed with the current profile.',
        );
        abort_if(
            $analyzed->count() > self::MAXIMUM_ANALYZED_ITEMS,
            422,
            sprintf(
                'Similarity ordering currently supports up to %d analyzed playlist entries.',
                self::MAXIMUM_ANALYZED_ITEMS,
            ),
        );
        abort_if(
            $analyzed->count() < 2,
            422,
            'At least two playlist entries need a current audio analysis vector.',
        );

        $similarities = $this->similarityMatrix($profile, $analyzed->pluck('artifactId')->unique()->all());
        $original = $analyzed->all();
        $greedy = $this->greedyRoute($analyzed, $openingItemId, $similarities);
        $optimized = $this->twoOpt($greedy, $similarities);
        $proposed = collect($optimized)->map(
            fn (array $item, int $index): array => [
                'itemId' => $item['itemId'],
                'trackId' => $item['trackId'],
                'analyzed' => true,
                'similarityToPrevious' => $index === 0
                    ? null
                    : round($this->similarity($optimized[$index - 1], $item, $similarities), 6),
            ],
        )->concat($unanalyzed->map(fn (PlaylistItem $item): array => [
            'itemId' => $item->id,
            'trackId' => $item->track_id,
            'analyzed' => false,
            'similarityToPrevious' => null,
        ]))->values();

        $previousAverage = $this->averageSimilarity($original, $similarities);
        $greedyAverage = $this->averageSimilarity($greedy, $similarities);
        $optimizedAverage = $this->averageSimilarity($optimized, $similarities);

        return [
            'profile' => [
                'id' => $profile->id,
                'modelName' => $profile->model_name,
                'modelVersion' => $profile->model_version,
            ],
            'algorithm' => 'greedy_2opt',
            'maximumAnalyzedItems' => self::MAXIMUM_ANALYZED_ITEMS,
            'openingItemId' => $openingItemId,
            'orderSignature' => $this->orderSignature($items->pluck('id')->all()),
            'canUndo' => $this->restorableSnapshot($playlist, $items->pluck('id')->all()) !== null,
            'summary' => [
                'analyzedCount' => $analyzed->count(),
                'unanalyzedCount' => $unanalyzed->count(),
                'previousAverageSimilarity' => $previousAverage,
                'greedyAverageSimilarity' => $greedyAverage,
                'optimizedAverageSimilarity' => $optimizedAverage,
                'improvement' => $previousAverage === null || $optimizedAverage === null
                    ? null
                    : round($optimizedAverage - $previousAverage, 6),
            ],
            'items' => $proposed->all(),
        ];
    }

    /**
     * @param  list<int>  $itemIds
     * @return array{itemIds: list<int>, canUndo: bool}
     */
    public function apply(Playlist $playlist, array $itemIds, string $orderSignature): array
    {
        $currentIds = $this->orderedItems($playlist)->pluck('id')->all();
        abort_unless(
            hash_equals($this->orderSignature($currentIds), $orderSignature),
            409,
            'The playlist changed after this preview was created. Create a new preview before applying it.',
        );
        $this->validateOrder($currentIds, $itemIds);

        DB::transaction(function () use ($currentIds, $itemIds, $playlist): void {
            PlaylistOrderSnapshot::create([
                'playlist_id' => $playlist->id,
                'item_ids' => $currentIds,
                'source' => 'audio_similarity',
            ]);
            $this->writeOrder($itemIds);
        });

        return ['itemIds' => $itemIds, 'canUndo' => true];
    }

    /** @return array{itemIds: list<int>, canUndo: bool} */
    public function restore(Playlist $playlist): array
    {
        $currentIds = $this->orderedItems($playlist)->pluck('id')->all();
        $snapshot = $this->restorableSnapshot($playlist, $currentIds);
        abort_if($snapshot === null, 409, 'There is no compatible similarity order to undo.');
        $snapshotIds = collect($snapshot->item_ids)->map(fn (mixed $id): int => (int) $id)->all();

        DB::transaction(function () use ($snapshot, $snapshotIds): void {
            $this->writeOrder($snapshotIds);
            $snapshot->update(['restored_at' => now()]);
        });

        return [
            'itemIds' => $snapshotIds,
            'canUndo' => $this->restorableSnapshot($playlist, $snapshotIds) !== null,
        ];
    }

    /** @return Collection<int, PlaylistItem> */
    private function orderedItems(Playlist $playlist): Collection
    {
        return $playlist->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'playlist_id', 'track_id', 'position']);
    }

    private function latestProfile(): ?AudioAnalysisProfile
    {
        return $this->profileSelector->current();
    }

    /**
     * @param  list<int>  $trackIds
     * @return Collection<int, int>
     */
    private function artifactsForTracks(AudioAnalysisProfile $profile, array $trackIds): Collection
    {
        $latestItemIds = AudioAnalysisRunItem::query()
            ->selectRaw('MAX(audio_analysis_run_items.id)')
            ->join(
                'audio_analysis_artifacts as profile_artifacts',
                'profile_artifacts.id',
                '=',
                'audio_analysis_run_items.audio_analysis_artifact_id',
            )
            ->join(
                'audio_analysis_vectors as profile_vectors',
                'profile_vectors.audio_analysis_artifact_id',
                '=',
                'audio_analysis_run_items.audio_analysis_artifact_id',
            )
            ->where('profile_artifacts.audio_analysis_profile_id', $profile->id)
            ->where('profile_vectors.audio_analysis_profile_id', $profile->id)
            ->whereIn('audio_analysis_run_items.status', ['completed', 'reused'])
            ->whereIn('audio_analysis_run_items.track_id', $trackIds)
            ->groupBy('audio_analysis_run_items.track_id');

        return AudioAnalysisRunItem::query()
            ->whereIn('id', $latestItemIds)
            ->pluck('audio_analysis_artifact_id', 'track_id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * @param  list<int>  $artifactIds
     * @return array<string, float>
     */
    private function similarityMatrix(AudioAnalysisProfile $profile, array $artifactIds): array
    {
        $ids = '{'.implode(',', array_map('intval', $artifactIds)).'}';
        $rows = DB::select(<<<'SQL'
            WITH selected_vectors AS MATERIALIZED (
                SELECT audio_analysis_artifact_id, embedding
                FROM audio_analysis_vectors
                WHERE audio_analysis_profile_id = ?
                    AND audio_analysis_artifact_id = ANY (?::bigint[])
            )
            SELECT
                source.audio_analysis_artifact_id AS source_id,
                candidate.audio_analysis_artifact_id AS candidate_id,
                GREATEST(-1, LEAST(1, 1 - (source.embedding <=> candidate.embedding))) AS similarity
            FROM selected_vectors AS source
            CROSS JOIN selected_vectors AS candidate
            WHERE source.audio_analysis_artifact_id <> candidate.audio_analysis_artifact_id
            SQL, [$profile->id, $ids]);

        return collect($rows)->mapWithKeys(fn (object $row): array => [
            $this->matrixKey((int) $row->source_id, (int) $row->candidate_id) => (float) $row->similarity,
        ])->all();
    }

    /**
     * @param  Collection<int, array{itemId: int, trackId: int, position: int, artifactId: int}>  $items
     * @param  array<string, float>  $similarities
     * @return list<array{itemId: int, trackId: int, position: int, artifactId: int}>
     */
    private function greedyRoute(Collection $items, int $openingItemId, array $similarities): array
    {
        $current = $items->firstWhere('itemId', $openingItemId);
        $remaining = $items->reject(fn (array $item): bool => $item['itemId'] === $openingItemId)->values();
        $route = [$current];

        while ($remaining->isNotEmpty()) {
            $next = $remaining
                ->sort(function (array $left, array $right) use ($current, $similarities): int {
                    $scoreComparison = $this->similarity($current, $right, $similarities)
                        <=> $this->similarity($current, $left, $similarities);

                    return $scoreComparison !== 0
                        ? $scoreComparison
                        : [$left['position'], $left['itemId']] <=> [$right['position'], $right['itemId']];
                })
                ->first();
            $route[] = $next;
            $remaining = $remaining->reject(
                fn (array $item): bool => $item['itemId'] === $next['itemId'],
            )->values();
            $current = $next;
        }

        return $route;
    }

    /**
     * @param  list<array{itemId: int, trackId: int, position: int, artifactId: int}>  $route
     * @param  array<string, float>  $similarities
     * @return list<array{itemId: int, trackId: int, position: int, artifactId: int}>
     */
    private function twoOpt(array $route, array $similarities): array
    {
        $count = count($route);
        for ($pass = 0; $pass < self::MAXIMUM_TWO_OPT_PASSES; $pass++) {
            $improved = false;
            for ($start = 1; $start < $count - 1; $start++) {
                for ($end = $start + 1; $end < $count; $end++) {
                    $before = $this->similarity($route[$start - 1], $route[$start], $similarities);
                    $after = $this->similarity($route[$start - 1], $route[$end], $similarities);
                    if ($end + 1 < $count) {
                        $before += $this->similarity($route[$end], $route[$end + 1], $similarities);
                        $after += $this->similarity($route[$start], $route[$end + 1], $similarities);
                    }
                    if ($after <= $before + self::IMPROVEMENT_EPSILON) {
                        continue;
                    }

                    array_splice($route, $start, $end - $start + 1, array_reverse(
                        array_slice($route, $start, $end - $start + 1),
                    ));
                    $improved = true;
                }
            }
            if (! $improved) {
                break;
            }
        }

        return $route;
    }

    /**
     * @param  array{artifactId: int}  $left
     * @param  array{artifactId: int}  $right
     * @param  array<string, float>  $similarities
     */
    private function similarity(array $left, array $right, array $similarities): float
    {
        if ($left['artifactId'] === $right['artifactId']) {
            return 1.0;
        }

        return $similarities[$this->matrixKey($left['artifactId'], $right['artifactId'])] ?? -1.0;
    }

    /**
     * @param  list<array{artifactId: int}>  $route
     * @param  array<string, float>  $similarities
     */
    private function averageSimilarity(array $route, array $similarities): ?float
    {
        if (count($route) < 2) {
            return null;
        }

        $total = 0.0;
        for ($index = 1; $index < count($route); $index++) {
            $total += $this->similarity($route[$index - 1], $route[$index], $similarities);
        }

        return round($total / (count($route) - 1), 6);
    }

    private function matrixKey(int $sourceId, int $candidateId): string
    {
        return "{$sourceId}:{$candidateId}";
    }

    /** @param list<int> $itemIds */
    private function orderSignature(array $itemIds): string
    {
        return hash('sha256', implode(',', $itemIds));
    }

    /**
     * @param  list<int>  $currentIds
     * @param  list<int>  $requestedIds
     */
    private function validateOrder(array $currentIds, array $requestedIds): void
    {
        $current = $currentIds;
        $requested = $requestedIds;
        sort($current);
        sort($requested);
        if ($current !== $requested) {
            throw ValidationException::withMessages([
                'items' => 'The proposed order must include every playlist item exactly once.',
            ]);
        }
    }

    /** @param list<int> $itemIds */
    private function writeOrder(array $itemIds): void
    {
        foreach ($itemIds as $position => $itemId) {
            PlaylistItem::query()->whereKey($itemId)->update(['position' => $position]);
        }
    }

    /** @param list<int> $currentIds */
    private function restorableSnapshot(Playlist $playlist, array $currentIds): ?PlaylistOrderSnapshot
    {
        $snapshot = $playlist->orderSnapshots()
            ->whereNull('restored_at')
            ->latest('id')
            ->first();
        if ($snapshot === null) {
            return null;
        }

        $snapshotIds = collect($snapshot->item_ids)->map(fn (mixed $id): int => (int) $id)->all();
        $current = $currentIds;
        sort($snapshotIds);
        sort($current);

        return $snapshotIds === $current ? $snapshot : null;
    }
}
