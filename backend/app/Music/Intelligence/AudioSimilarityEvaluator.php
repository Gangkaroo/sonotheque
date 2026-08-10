<?php

namespace App\Music\Intelligence;

use App\Models\AudioAnalysisProfile;
use App\Models\AudioAnalysisRunItem;
use App\Models\AudioSimilarityFeedback;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class AudioSimilarityEvaluator
{
    private const MAXIMUM_MATCHES = 25;

    private const REVIEW_MATCH_COUNT = 10;

    private const REVIEW_SOURCE_COUNT = 30;

    private const OVERVIEW_SAMPLE_COUNT = 500;

    private const CONFIGURATIONS = [
        'all',
        'exclude_album',
        'exclude_artist',
        'exclude_album_artist',
    ];

    public function __construct(
        private readonly AudioVectorIndex $vectorIndex,
        private readonly AudioSimilarityReranker $reranker,
        private readonly AudioSimilarityPersonalizer $personalizer,
        private readonly AudioAnalysisProfileSelector $profileSelector,
    ) {
    }

    /**
     * @return array{
     *     profile: ?array<string, mixed>,
     *     analyzedTrackCount: int,
     *     coverage: array{rootCount: int, artistCount: int, albumCount: int},
     *     distributions: array<string, array<string, mixed>>,
     *     feedbackSummary: array{relevant: int, irrelevant: int},
     *     review: array<string, mixed>,
     *     tracks: list<array<string, mixed>>
     * }
     */
    public function overview(): array
    {
        $profile = $this->latestProfile();
        if ($profile === null) {
            return [
                'profile' => null,
                'analyzedTrackCount' => 0,
                'coverage' => [
                    'rootCount' => 0,
                    'artistCount' => 0,
                    'albumCount' => 0,
                ],
                'distributions' => [],
                'feedbackSummary' => [
                    'relevant' => 0,
                    'irrelevant' => 0,
                ],
                'review' => $this->emptyReview(),
                'tracks' => [],
            ];
        }

        $items = $this->itemsForProfile(
            $profile,
            includeEmbeddings: false,
            limit: self::OVERVIEW_SAMPLE_COUNT,
        );
        $review = $this->review($profile, $items);

        return [
            'profile' => $this->profilePayload($profile),
            'analyzedTrackCount' => $this->itemQueryForProfile($profile)->count(),
            'coverage' => $this->coverage($profile),
            'distributions' => $this->distributions($items),
            'feedbackSummary' => $this->feedbackSummary($profile),
            'review' => $review,
            'tracks' => collect($review['sources'])
                ->map(function (array $source): array {
                    unset($source['configurations']);

                    return $source;
                })
                ->sortBy([
                    ['artistName', 'asc'],
                    ['albumTitle', 'asc'],
                    ['discNumber', 'asc'],
                    ['trackNumber', 'asc'],
                    ['title', 'asc'],
                ], SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return null|array{
     *     profile: array<string, mixed>,
     *     source: array<string, mixed>,
     *     candidateCount: int,
     *     calculationMs: float,
     *     matches: list<array<string, mixed>>
     * }
     */
    public function evaluate(
        int $trackId,
        int $limit = 10,
        bool $excludeSameAlbum = false,
        bool $excludeSameArtist = false,
        array $reranking = [
            'enabled' => false,
            'tempoInfluence' => 5,
            'keyInfluence' => 3,
            'intensityInfluence' => 4,
        ],
        bool $personalizationEnabled = false,
    ): ?array {
        $profile = $this->latestProfile();
        if ($profile === null) {
            return null;
        }

        $source = $this->itemsForTrackIds($profile, [$trackId])->first();
        if ($source === null || $source->audio_analysis_artifact_id === null) {
            return null;
        }

        $personalized = $this->personalizer->apply(
            $profile,
            $reranking,
            $personalizationEnabled,
        );
        $effectiveReranking = $personalized['preferences'];
        $limit = max(1, min(self::MAXIMUM_MATCHES, $limit));
        $candidateLimit = $this->reranker->candidateLimit($limit, $effectiveReranking);
        $started = hrtime(true);
        $sourcePayload = $this->trackPayload($source);
        $search = $this->vectorIndex->nearestTracks(
            $profile,
            $source->audio_analysis_artifact_id,
            $source->track_id,
            $sourcePayload['albumId'],
            collect($sourcePayload['artists'])->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $candidateLimit,
            $excludeSameAlbum,
            $excludeSameArtist,
        );
        if ($search === null) {
            return null;
        }

        $configuration = $this->configuration($excludeSameAlbum, $excludeSameArtist);
        $feedback = AudioSimilarityFeedback::query()
            ->where('audio_analysis_profile_id', $profile->id)
            ->where('source_track_id', $source->track_id)
            ->where('configuration', $configuration)
            ->pluck('verdict', 'candidate_track_id');
        $itemsByTrackId = $this->itemsForTrackIds(
            $profile,
            array_column($search['matches'], 'trackId'),
        )->keyBy('track_id');
        $matches = $this->reranker->rerank(
            $sourcePayload,
            collect($search['matches'])
            ->map(function (array $match) use ($feedback, $itemsByTrackId): ?array {
                $item = $itemsByTrackId->get($match['trackId']);
                if ($item === null) {
                    return null;
                }

                return [
                    ...$this->trackPayload($item),
                    'similarity' => round($match['similarity'], 6),
                    'feedback' => $feedback[$match['trackId']] ?? null,
                ];
            })
            ->filter()
            ->values(),
            $effectiveReranking,
        );
        $calculationMs = (hrtime(true) - $started) / 1_000_000;

        return [
            'profile' => $this->profilePayload($profile),
            'source' => $sourcePayload,
            'candidateCount' => $search['candidateCount'],
            'calculationMs' => round($calculationMs, 3),
            'filters' => [
                'excludeSameAlbum' => $excludeSameAlbum,
                'excludeSameArtist' => $excludeSameArtist,
            ],
            'ranking' => [
                'method' => match (true) {
                    $personalized['personalization']['applied'] => 'personalized',
                    $effectiveReranking['enabled'] => 'feature_reranking',
                    default => 'embedding',
                },
                'candidatePoolSize' => count($search['matches']),
                'preferences' => $effectiveReranking,
                'personalization' => $personalized['personalization'],
            ],
            'matches' => $matches->take($limit)->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function recordFeedback(
        int $sourceTrackId,
        int $candidateTrackId,
        string $verdict,
        bool $excludeSameAlbum = false,
        bool $excludeSameArtist = false,
    ): array {
        $profile = $this->latestProfile();
        abort_if($profile === null, 404, 'No compatible audio analysis profile is available.');
        abort_if($sourceTrackId === $candidateTrackId, 422, 'A track cannot be rated against itself.');
        $trackIds = $this->trackIdsForProfile($profile);
        abort_unless(
            $trackIds->contains($sourceTrackId) && $trackIds->contains($candidateTrackId),
            404,
            'Both tracks require compatible audio analysis artifacts.',
        );

        $configuration = $this->configuration($excludeSameAlbum, $excludeSameArtist);
        AudioSimilarityFeedback::query()->updateOrCreate(
            [
                'audio_analysis_profile_id' => $profile->id,
                'source_track_id' => $sourceTrackId,
                'candidate_track_id' => $candidateTrackId,
                'configuration' => $configuration,
            ],
            ['verdict' => $verdict],
        );

        return $this->feedbackResponse($profile, $verdict);
    }

    /** @return array<string, mixed> */
    public function removeFeedback(
        int $sourceTrackId,
        int $candidateTrackId,
        bool $excludeSameAlbum = false,
        bool $excludeSameArtist = false,
    ): array {
        $profile = $this->latestProfile();
        abort_if($profile === null, 404, 'No compatible audio analysis profile is available.');

        AudioSimilarityFeedback::query()
            ->where('audio_analysis_profile_id', $profile->id)
            ->where('source_track_id', $sourceTrackId)
            ->where('candidate_track_id', $candidateTrackId)
            ->where(
                'configuration',
                $this->configuration($excludeSameAlbum, $excludeSameArtist),
            )
            ->delete();

        return $this->feedbackResponse($profile, null);
    }

    private function latestProfile(): ?AudioAnalysisProfile
    {
        return $this->profileSelector->current();
    }

    /** @return Collection<int, int> */
    private function trackIdsForProfile(AudioAnalysisProfile $profile): Collection
    {
        return $this->itemQueryForProfile($profile)->pluck('track_id');
    }

    /** @return Collection<int, AudioAnalysisRunItem> */
    private function itemsForProfile(
        AudioAnalysisProfile $profile,
        bool $includeEmbeddings,
        ?int $limit = null,
    ): Collection {
        $artifactColumns = [
            'id',
            'audio_analysis_profile_id',
            'features',
        ];
        if ($includeEmbeddings) {
            $artifactColumns[] = 'embedding';
        }

        $query = $this->itemQueryForProfile($profile)
            ->with([
                'artifact' => fn ($query) => $query->select($artifactColumns),
                'libraryRoot:id,name',
                'track.album.primaryArtist',
                'track.artists',
                'track.genres:id,name',
            ])
            ->orderByRaw(
                "MD5(CAST(track_id AS TEXT) || ?) ASC",
                [$profile->profile_key],
            );

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->values();
    }

    /**
     * @param  list<int>  $trackIds
     * @return Collection<int, AudioAnalysisRunItem>
     */
    private function itemsForTrackIds(
        AudioAnalysisProfile $profile,
        array $trackIds,
    ): Collection {
        if ($trackIds === []) {
            return collect();
        }

        return $this->itemQueryForProfile($profile)
            ->whereIn('track_id', $trackIds)
            ->with([
                'artifact:id,audio_analysis_profile_id,features',
                'libraryRoot:id,name',
                'track.album.primaryArtist',
                'track.artists',
                'track.genres:id,name',
            ])
            ->get()
            ->values();
    }

    /** @return Builder<AudioAnalysisRunItem> */
    private function itemQueryForProfile(AudioAnalysisProfile $profile): Builder
    {
        $latestItemIds = AudioAnalysisRunItem::query()
            ->selectRaw('MAX(audio_analysis_run_items.id)')
            ->join(
                'audio_analysis_artifacts as profile_artifacts',
                'profile_artifacts.id',
                '=',
                'audio_analysis_run_items.audio_analysis_artifact_id',
            )
            ->where('profile_artifacts.audio_analysis_profile_id', $profile->id)
            ->whereIn('audio_analysis_run_items.status', ['completed', 'reused'])
            ->whereNotNull('audio_analysis_run_items.track_id')
            ->groupBy('audio_analysis_run_items.track_id');

        return AudioAnalysisRunItem::query()
            ->whereIn('audio_analysis_run_items.id', $latestItemIds);
    }

    /** @return array<string, mixed> */
    private function profilePayload(AudioAnalysisProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'analyzerName' => $profile->analyzer_name,
            'analyzerVersion' => $profile->analyzer_version,
            'analyzerLicense' => $profile->analyzer_license,
            'modelName' => $profile->model_name,
            'modelVersion' => $profile->model_version,
            'modelChecksum' => $profile->model_checksum,
            'modelLicense' => $profile->model_license,
            'embeddingDimensions' => $profile->embedding_dimensions,
        ];
    }

    /** @return array<string, mixed> */
    private function trackPayload(AudioAnalysisRunItem $item): array
    {
        /** @var Track $track */
        $track = $item->track;
        $artists = $track->artists
            ->sortBy(fn ($artist): int => (int) ($artist->pivot?->position ?? 0))
            ->map(fn ($artist): array => [
                'id' => $artist->id,
                'name' => $artist->name,
            ])
            ->values();
        if ($artists->isEmpty() && $track->album?->primaryArtist !== null) {
            $artists->push([
                'id' => $track->album->primaryArtist->id,
                'name' => $track->album->primaryArtist->name,
            ]);
        }

        $artistName = $artists->pluck('name')->join(', ');
        $albumTitle = $track->album?->title ?? '';

        return [
            'id' => $track->id,
            'title' => $track->title,
            'streamUrl' => "/api/tracks/{$track->id}/stream",
            'durationMs' => $track->duration_ms,
            'label' => collect([$artistName, $track->title, $albumTitle])
                ->filter(fn (string $part): bool => $part !== '')
                ->join(' · '),
            'artistName' => $artistName,
            'artists' => $artists->all(),
            'albumId' => $track->album?->id,
            'albumTitle' => $albumTitle,
            'albumOriginalReleaseYear' => $track->album?->original_release_year,
            'albumArtworkThumbnailUrl' => $track->album?->artwork_id
                ? "/api/artwork/{$track->album->artwork_id}/thumbnail"
                : null,
            'year' => $track->year ?? $track->album?->original_release_year,
            'discNumber' => $track->disc_number,
            'trackNumber' => $track->track_number,
            'libraryRootId' => $item->library_root_id,
            'libraryRootName' => $item->libraryRoot?->name ?? '',
            'genreIds' => $track->genres->pluck('id')->all(),
            'features' => $this->featurePayload($item->artifact?->features),
        ];
    }

    /**
     * @param  Collection<int, AudioAnalysisRunItem>  $items
     * @return array<string, mixed>
     */
    private function review(AudioAnalysisProfile $profile, Collection $items): array
    {
        $payloadsByTrackId = $items->mapWithKeys(
            fn (AudioAnalysisRunItem $item): array => [
                $item->track_id => $this->trackPayload($item),
            ],
        );
        $sources = $this->representativeSources($profile, $items, $payloadsByTrackId);
        if ($sources->isEmpty()) {
            return $this->emptyReview();
        }

        $feedback = AudioSimilarityFeedback::query()
            ->where('audio_analysis_profile_id', $profile->id)
            ->whereIn('source_track_id', $sources->pluck('track_id'))
            ->get(['source_track_id', 'configuration', 'verdict'])
            ->groupBy(['source_track_id', 'configuration']);
        $allPayloads = $payloadsByTrackId->values();
        $sourcePayloads = $sources->map(function (AudioAnalysisRunItem $source) use (
            $allPayloads,
            $feedback,
            $payloadsByTrackId,
        ): array {
            $payload = $payloadsByTrackId->get($source->track_id);
            $configurationProgress = collect(self::CONFIGURATIONS)
                ->mapWithKeys(function (string $configuration) use (
                    $allPayloads,
                    $feedback,
                    $payload,
                    $source,
                ): array {
                    $ratings = $feedback
                        ->get($source->track_id, collect())
                        ->get($configuration, collect());
                    $relevant = $ratings->where('verdict', 'relevant')->count();
                    $irrelevant = $ratings->where('verdict', 'irrelevant')->count();
                    $required = min(
                        self::REVIEW_MATCH_COUNT,
                        $this->candidateCount($payload, $allPayloads, $configuration),
                    );

                    return [$configuration => [
                        'required' => $required,
                        'rated' => $relevant + $irrelevant,
                        'relevant' => $relevant,
                        'irrelevant' => $irrelevant,
                        'complete' => $required > 0 && ($relevant + $irrelevant) >= $required,
                    ]];
                })
                ->all();

            return [
                ...$payload,
                'configurations' => $configurationProgress,
            ];
        })->values();

        return [
            'targetSourceCount' => $sourcePayloads->count(),
            'matchCount' => self::REVIEW_MATCH_COUNT,
            'sources' => $sourcePayloads->all(),
            'quality' => collect(self::CONFIGURATIONS)
                ->mapWithKeys(
                    fn (string $configuration): array => [
                        $configuration => $this->qualityMetrics(
                            $sourcePayloads,
                            $configuration,
                        ),
                    ],
                )
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, AudioAnalysisRunItem>  $items
     * @param  Collection<int, array<string, mixed>>  $payloadsByTrackId
     * @return Collection<int, AudioAnalysisRunItem>
     */
    private function representativeSources(
        AudioAnalysisProfile $profile,
        Collection $items,
        Collection $payloadsByTrackId,
    ): Collection {
        $remaining = $items->values();
        $selected = collect();
        $rootCounts = [];
        $genreCounts = [];
        $artistCounts = [];
        $albumCounts = [];
        $limit = min(self::REVIEW_SOURCE_COUNT, $remaining->count());

        while ($selected->count() < $limit && $remaining->isNotEmpty()) {
            $candidate = $remaining
                ->sortBy(function (AudioAnalysisRunItem $item) use (
                    $albumCounts,
                    $artistCounts,
                    $genreCounts,
                    $payloadsByTrackId,
                    $profile,
                    $rootCounts,
                ): string {
                    $payload = $payloadsByTrackId->get($item->track_id);
                    $artistId = collect($payload['artists'])->pluck('id')->first();
                    $genreId = $payload['genreIds'][0] ?? null;

                    return sprintf(
                        '%04d:%04d:%04d:%04d:%s',
                        $rootCounts[$item->library_root_id] ?? 0,
                        $genreCounts[$genreId ?? 'none'] ?? 0,
                        $artistCounts[$artistId ?? 'none'] ?? 0,
                        $albumCounts[$payload['albumId'] ?? 'none'] ?? 0,
                        hash('sha256', $profile->profile_key.':'.$item->track_id),
                    );
                })
                ->first();
            if ($candidate === null) {
                break;
            }

            $payload = $payloadsByTrackId->get($candidate->track_id);
            $artistId = collect($payload['artists'])->pluck('id')->first();
            $genreId = $payload['genreIds'][0] ?? null;
            $rootCounts[$candidate->library_root_id] =
                ($rootCounts[$candidate->library_root_id] ?? 0) + 1;
            $genreCounts[$genreId ?? 'none'] = ($genreCounts[$genreId ?? 'none'] ?? 0) + 1;
            $artistCounts[$artistId ?? 'none'] = ($artistCounts[$artistId ?? 'none'] ?? 0) + 1;
            $albumCounts[$payload['albumId'] ?? 'none'] =
                ($albumCounts[$payload['albumId'] ?? 'none'] ?? 0) + 1;
            $selected->push($candidate);
            $remaining = $remaining->reject(
                fn (AudioAnalysisRunItem $item): bool => $item->track_id === $candidate->track_id,
            );
        }

        return $selected->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     */
    private function candidateCount(
        array $source,
        Collection $candidates,
        string $configuration,
    ): int {
        $sourceArtistIds = collect($source['artists'])->pluck('id');
        $excludeSameAlbum = in_array(
            $configuration,
            ['exclude_album', 'exclude_album_artist'],
            true,
        );
        $excludeSameArtist = in_array(
            $configuration,
            ['exclude_artist', 'exclude_album_artist'],
            true,
        );

        return $candidates->filter(function (array $candidate) use (
            $excludeSameAlbum,
            $excludeSameArtist,
            $source,
            $sourceArtistIds,
        ): bool {
            if ($candidate['id'] === $source['id']) {
                return false;
            }
            if ($excludeSameAlbum
                && $source['albumId'] !== null
                && $candidate['albumId'] === $source['albumId']) {
                return false;
            }

            return ! ($excludeSameArtist && $sourceArtistIds->intersect(
                collect($candidate['artists'])->pluck('id'),
            )->isNotEmpty());
        })->count();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $sources
     * @return array<string, int|float|null>
     */
    private function qualityMetrics(Collection $sources, string $configuration): array
    {
        $progress = $sources->pluck("configurations.{$configuration}");
        $relevant = (int) $progress->sum('relevant');
        $irrelevant = (int) $progress->sum('irrelevant');
        $rated = $relevant + $irrelevant;
        $completed = $progress->where('complete', true);

        return [
            'startedSourceCount' => $progress->where('rated', '>', 0)->count(),
            'completedSourceCount' => $completed->count(),
            'ratedMatchCount' => $rated,
            'relevant' => $relevant,
            'irrelevant' => $irrelevant,
            'relevanceRate' => $rated === 0 ? null : round($relevant / $rated, 4),
            'meanRelevantShare' => $completed->isEmpty()
                ? null
                : round($completed->avg(
                    fn (array $source): float => $source['required'] === 0
                        ? 0.0
                        : $source['relevant'] / $source['required'],
                ), 4),
        ];
    }

    /** @return array<string, mixed> */
    private function feedbackResponse(
        AudioAnalysisProfile $profile,
        ?string $feedback,
    ): array {
        $items = $this->itemsForProfile($profile, includeEmbeddings: false);

        return [
            'feedback' => $feedback,
            'feedbackSummary' => $this->feedbackSummary($profile),
            'review' => $this->review($profile, $items),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyReview(): array
    {
        return [
            'targetSourceCount' => 0,
            'matchCount' => self::REVIEW_MATCH_COUNT,
            'sources' => [],
            'quality' => collect(self::CONFIGURATIONS)
                ->mapWithKeys(fn (string $configuration): array => [$configuration => [
                    'startedSourceCount' => 0,
                    'completedSourceCount' => 0,
                    'ratedMatchCount' => 0,
                    'relevant' => 0,
                    'irrelevant' => 0,
                    'relevanceRate' => null,
                    'meanRelevantShare' => null,
                ]])
                ->all(),
        ];
    }

    private function configuration(bool $excludeSameAlbum, bool $excludeSameArtist): string
    {
        return match (true) {
            $excludeSameAlbum && $excludeSameArtist => 'exclude_album_artist',
            $excludeSameAlbum => 'exclude_album',
            $excludeSameArtist => 'exclude_artist',
            default => 'all',
        };
    }

    /**
     * @return array{rootCount: int, artistCount: int, albumCount: int}
     */
    private function coverage(AudioAnalysisProfile $profile): array
    {
        $analyzedTracks = $this->itemQueryForProfile($profile)
            ->select('audio_analysis_run_items.track_id');
        $catalogCoverage = DB::table('tracks')
            ->joinSub(
                $analyzedTracks,
                'analyzed_tracks',
                'analyzed_tracks.track_id',
                '=',
                'tracks.id',
            )
            ->leftJoin('albums', 'albums.id', '=', 'tracks.album_id')
            ->leftJoin('artist_track', 'artist_track.track_id', '=', 'tracks.id')
            ->selectRaw('COUNT(DISTINCT tracks.album_id) AS album_count')
            ->selectRaw(
                'COUNT(DISTINCT COALESCE(artist_track.artist_id, albums.primary_artist_id))'
                    .' AS artist_count',
            )
            ->first();

        return [
            'rootCount' => $this->itemQueryForProfile($profile)
                ->whereNotNull('library_root_id')
                ->distinct()
                ->count('library_root_id'),
            'artistCount' => (int) ($catalogCoverage?->artist_count ?? 0),
            'albumCount' => (int) ($catalogCoverage?->album_count ?? 0),
        ];
    }

    /**
     * @param  Collection<int, AudioAnalysisRunItem>  $items
     * @return array<string, array<string, mixed>>
     */
    private function distributions(Collection $items): array
    {
        $features = $items
            ->map(fn (AudioAnalysisRunItem $item): array => $item->artifact?->features ?? []);

        return collect(['bpm', 'danceability', 'dynamicComplexity', 'loudness'])
            ->mapWithKeys(function (string $feature) use ($features): array {
                $values = $features
                    ->pluck($feature)
                    ->filter(fn (mixed $value): bool => is_numeric($value))
                    ->map(fn (mixed $value): float => (float) $value)
                    ->sort()
                    ->values()
                    ->all();

                return $values === [] ? [] : [$feature => $this->distribution($values)];
            })
            ->all();
    }

    /**
     * @param  list<float>  $values
     * @return array<string, mixed>
     */
    private function distribution(array $values): array
    {
        $minimum = $values[0];
        $maximum = $values[array_key_last($values)];
        $binCount = 8;
        $width = $maximum === $minimum ? 1.0 : ($maximum - $minimum) / $binCount;
        $bins = array_fill(0, $binCount, 0);

        foreach ($values as $value) {
            $index = $maximum === $minimum
                ? 0
                : min($binCount - 1, (int) floor(($value - $minimum) / $width));
            $bins[$index]++;
        }

        return [
            'count' => count($values),
            'minimum' => round($minimum, 3),
            'maximum' => round($maximum, 3),
            'mean' => round(array_sum($values) / count($values), 3),
            'median' => round($this->percentile($values, 0.5), 3),
            'lowerQuartile' => round($this->percentile($values, 0.25), 3),
            'upperQuartile' => round($this->percentile($values, 0.75), 3),
            'bins' => collect($bins)
                ->map(fn (int $count, int $index): array => [
                    'minimum' => round($minimum + ($index * $width), 3),
                    'maximum' => round(
                        $index === $binCount - 1
                            ? $maximum
                            : $minimum + (($index + 1) * $width),
                        3,
                    ),
                    'count' => $count,
                ])
                ->all(),
        ];
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        $position = (count($values) - 1) * $percentile;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return $values[$lower];
        }

        return $values[$lower] + (($values[$upper] - $values[$lower]) * ($position - $lower));
    }

    /** @return array{relevant: int, irrelevant: int} */
    private function feedbackSummary(AudioAnalysisProfile $profile): array
    {
        $counts = AudioSimilarityFeedback::query()
            ->where('audio_analysis_profile_id', $profile->id)
            ->selectRaw('verdict, COUNT(*) AS aggregate')
            ->groupBy('verdict')
            ->pluck('aggregate', 'verdict');

        return [
            'relevant' => (int) ($counts['relevant'] ?? 0),
            'irrelevant' => (int) ($counts['irrelevant'] ?? 0),
        ];
    }

    /**
     * @param  null|array<string, mixed>  $features
     * @return array<string, mixed>
     */
    private function featurePayload(?array $features): array
    {
        $features ??= [];

        return array_intersect_key($features, array_flip([
            'bpm',
            'danceability',
            'dynamicComplexity',
            'loudness',
            'key',
            'scale',
            'keyStrength',
        ]));
    }

}
