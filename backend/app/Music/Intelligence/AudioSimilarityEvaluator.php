<?php

namespace App\Music\Intelligence;

use App\Models\AudioAnalysisProfile;
use App\Models\AudioAnalysisRunItem;
use App\Models\AudioSimilarityFeedback;
use App\Models\Track;
use Illuminate\Support\Collection;

class AudioSimilarityEvaluator
{
    private const MAXIMUM_MATCHES = 25;

    /**
     * @return array{
     *     profile: ?array<string, mixed>,
     *     analyzedTrackCount: int,
     *     coverage: array{rootCount: int, artistCount: int, albumCount: int},
     *     distributions: array<string, array<string, mixed>>,
     *     feedbackSummary: array{relevant: int, irrelevant: int},
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
                'tracks' => [],
            ];
        }

        $items = $this->itemsForProfile($profile, includeEmbeddings: false);

        return [
            'profile' => $this->profilePayload($profile),
            'analyzedTrackCount' => $items->count(),
            'coverage' => $this->coverage($items),
            'distributions' => $this->distributions($items),
            'feedbackSummary' => $this->feedbackSummary($profile),
            'tracks' => $items
                ->map(fn (AudioAnalysisRunItem $item): array => $this->trackPayload($item))
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
    ): ?array {
        $profile = $this->latestProfile();
        if ($profile === null) {
            return null;
        }

        $items = $this->itemsForProfile($profile, includeEmbeddings: true);
        $source = $items->firstWhere('track_id', $trackId);
        if ($source === null || ! is_array($source->artifact?->embedding)) {
            return null;
        }

        $limit = max(1, min(self::MAXIMUM_MATCHES, $limit));
        $started = hrtime(true);
        $matches = [];
        $sourcePayload = $this->trackPayload($source);
        $sourceArtistIds = collect($sourcePayload['artists'])->pluck('id');
        $feedback = AudioSimilarityFeedback::query()
            ->where('audio_analysis_profile_id', $profile->id)
            ->where('source_track_id', $source->track_id)
            ->pluck('verdict', 'candidate_track_id');

        foreach ($items as $candidate) {
            if ($candidate->track_id === $source->track_id
                || ! is_array($candidate->artifact?->embedding)) {
                continue;
            }

            $candidatePayload = $this->trackPayload($candidate);
            if ($excludeSameAlbum
                && $sourcePayload['albumId'] !== null
                && $candidatePayload['albumId'] === $sourcePayload['albumId']) {
                continue;
            }
            if ($excludeSameArtist
                && $sourceArtistIds->intersect(
                    collect($candidatePayload['artists'])->pluck('id'),
                )->isNotEmpty()) {
                continue;
            }

            $score = $this->cosineSimilarity(
                $source->artifact->embedding,
                $candidate->artifact->embedding,
            );
            if ($score === null) {
                continue;
            }

            $matches[] = [
                ...$candidatePayload,
                'similarity' => round($score, 6),
                'feedback' => $feedback[$candidate->track_id] ?? null,
            ];
        }

        usort(
            $matches,
            static fn (array $left, array $right): int => $right['similarity'] <=> $left['similarity']
                ?: strnatcasecmp($left['label'], $right['label']),
        );
        $calculationMs = (hrtime(true) - $started) / 1_000_000;

        return [
            'profile' => $this->profilePayload($profile),
            'source' => $sourcePayload,
            'candidateCount' => count($matches),
            'calculationMs' => round($calculationMs, 3),
            'filters' => [
                'excludeSameAlbum' => $excludeSameAlbum,
                'excludeSameArtist' => $excludeSameArtist,
            ],
            'matches' => array_slice($matches, 0, $limit),
        ];
    }

    /** @return array{feedback: string, feedbackSummary: array{relevant: int, irrelevant: int}} */
    public function recordFeedback(int $sourceTrackId, int $candidateTrackId, string $verdict): array
    {
        $profile = $this->latestProfile();
        abort_if($profile === null, 404, 'No compatible audio analysis profile is available.');
        abort_if($sourceTrackId === $candidateTrackId, 422, 'A track cannot be rated against itself.');
        $trackIds = $this->trackIdsForProfile($profile);
        abort_unless(
            $trackIds->contains($sourceTrackId) && $trackIds->contains($candidateTrackId),
            404,
            'Both tracks require compatible audio analysis artifacts.',
        );

        AudioSimilarityFeedback::query()->updateOrCreate(
            [
                'audio_analysis_profile_id' => $profile->id,
                'source_track_id' => $sourceTrackId,
                'candidate_track_id' => $candidateTrackId,
            ],
            ['verdict' => $verdict],
        );

        return [
            'feedback' => $verdict,
            'feedbackSummary' => $this->feedbackSummary($profile),
        ];
    }

    /** @return array{feedback: null, feedbackSummary: array{relevant: int, irrelevant: int}} */
    public function removeFeedback(int $sourceTrackId, int $candidateTrackId): array
    {
        $profile = $this->latestProfile();
        abort_if($profile === null, 404, 'No compatible audio analysis profile is available.');

        AudioSimilarityFeedback::query()
            ->where('audio_analysis_profile_id', $profile->id)
            ->where('source_track_id', $sourceTrackId)
            ->where('candidate_track_id', $candidateTrackId)
            ->delete();

        return [
            'feedback' => null,
            'feedbackSummary' => $this->feedbackSummary($profile),
        ];
    }

    private function latestProfile(): ?AudioAnalysisProfile
    {
        return AudioAnalysisProfile::query()
            ->whereHas('artifacts.runItems.track')
            ->latest('id')
            ->first();
    }

    /** @return Collection<int, int> */
    private function trackIdsForProfile(AudioAnalysisProfile $profile): Collection
    {
        return $this->itemsForProfile($profile, includeEmbeddings: false)->pluck('track_id');
    }

    /** @return Collection<int, AudioAnalysisRunItem> */
    private function itemsForProfile(
        AudioAnalysisProfile $profile,
        bool $includeEmbeddings,
    ): Collection {
        $artifactColumns = [
            'id',
            'audio_analysis_profile_id',
            'features',
        ];
        if ($includeEmbeddings) {
            $artifactColumns[] = 'embedding';
        }

        return AudioAnalysisRunItem::query()
            ->whereNotNull('audio_analysis_artifact_id')
            ->whereIn('status', ['completed', 'reused'])
            ->whereHas(
                'artifact',
                fn ($query) => $query->where('audio_analysis_profile_id', $profile->id),
            )
            ->whereHas('track')
            ->with([
                'artifact' => fn ($query) => $query->select($artifactColumns),
                'libraryRoot:id,name',
                'track.album.primaryArtist',
                'track.artists',
            ])
            ->latest('id')
            ->get()
            ->unique('track_id')
            ->values();
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
            'label' => collect([$artistName, $track->title, $albumTitle])
                ->filter(fn (string $part): bool => $part !== '')
                ->join(' · '),
            'artistName' => $artistName,
            'artists' => $artists->all(),
            'albumId' => $track->album?->id,
            'albumTitle' => $albumTitle,
            'year' => $track->year ?? $track->album?->original_release_year,
            'discNumber' => $track->disc_number,
            'trackNumber' => $track->track_number,
            'libraryRootId' => $item->library_root_id,
            'libraryRootName' => $item->libraryRoot?->name ?? '',
            'features' => $this->featurePayload($item->artifact?->features),
        ];
    }

    /**
     * @param  Collection<int, AudioAnalysisRunItem>  $items
     * @return array{rootCount: int, artistCount: int, albumCount: int}
     */
    private function coverage(Collection $items): array
    {
        $payloads = $items->map(fn (AudioAnalysisRunItem $item): array => $this->trackPayload($item));

        return [
            'rootCount' => $items->pluck('library_root_id')->filter()->unique()->count(),
            'artistCount' => $payloads
                ->flatMap(fn (array $track): array => $track['artists'])
                ->pluck('id')
                ->unique()
                ->count(),
            'albumCount' => $payloads->pluck('albumId')->filter()->unique()->count(),
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

    /**
     * @param  list<mixed>  $left
     * @param  list<mixed>  $right
     */
    private function cosineSimilarity(array $left, array $right): ?float
    {
        if ($left === [] || count($left) !== count($right)) {
            return null;
        }

        $dotProduct = 0.0;
        $leftMagnitude = 0.0;
        $rightMagnitude = 0.0;

        foreach ($left as $index => $leftValue) {
            $rightValue = $right[$index] ?? null;
            if (! is_numeric($leftValue) || ! is_numeric($rightValue)) {
                return null;
            }

            $leftFloat = (float) $leftValue;
            $rightFloat = (float) $rightValue;
            $dotProduct += $leftFloat * $rightFloat;
            $leftMagnitude += $leftFloat * $leftFloat;
            $rightMagnitude += $rightFloat * $rightFloat;
        }

        if ($leftMagnitude === 0.0 || $rightMagnitude === 0.0) {
            return null;
        }

        return max(-1.0, min(
            1.0,
            $dotProduct / (sqrt($leftMagnitude) * sqrt($rightMagnitude)),
        ));
    }
}
