<?php

namespace App\Music\Intelligence;

use App\Models\ApplicationSetting;
use App\Models\AudioAnalysisProfile;
use App\Models\AudioAnalysisRunItem;
use App\Models\AudioSimilarityFeedback;
use App\Models\AudioSimilarityPersonalization;
use Illuminate\Support\Collection;

class AudioSimilarityPersonalizer
{
    public const MINIMUM_FEEDBACK_COUNT = 20;

    public const MINIMUM_VERDICT_COUNT = 5;

    private const MAXIMUM_ADJUSTMENT = 3.0;

    public function __construct(
        private readonly AudioSimilarityReranker $reranker,
        private readonly AudioAnalysisProfileSelector $profileSelector,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(ApplicationSetting $settings): array
    {
        $profile = $this->latestProfile();
        $counts = $profile === null
            ? $this->emptyVerdictCounts()
            : $this->feedbackCounts($profile);
        $personalization = $profile === null
            ? null
            : AudioSimilarityPersonalization::query()
                ->where('audio_analysis_profile_id', $profile->id)
                ->first();

        return $this->payload($settings, $profile, $personalization, $counts);
    }

    /** @return array<string, mixed> */
    public function train(ApplicationSetting $settings): array
    {
        $profile = $this->latestProfile();
        abort_if($profile === null, 409, 'Analyze tracks before training personalization.');
        $feedback = $this->feedback($profile);
        $counts = $this->verdictCounts($feedback);
        abort_unless(
            $this->canTrain($counts),
            409,
            sprintf(
                'Personalization requires at least %d ratings, including %d relevant and %d not relevant ratings.',
                self::MINIMUM_FEEDBACK_COUNT,
                self::MINIMUM_VERDICT_COUNT,
                self::MINIMUM_VERDICT_COUNT,
            ),
        );

        $features = $this->featuresForTracks(
            $profile,
            $feedback->flatMap(fn (AudioSimilarityFeedback $item): array => [
                $item->source_track_id,
                $item->candidate_track_id,
            ])->unique()->values()->all(),
        );
        $samples = $feedback->map(function (AudioSimilarityFeedback $item) use ($features): array {
            $source = $features->get($item->source_track_id, []);
            $candidate = $features->get($item->candidate_track_id, []);

            return [
                'verdict' => $item->verdict,
                'compatibility' => $this->reranker->compatibilities(
                    ['features' => $source],
                    ['features' => $candidate],
                ),
            ];
        });
        [$adjustments, $statistics] = $this->learn($samples);
        $personalization = AudioSimilarityPersonalization::query()->updateOrCreate(
            ['audio_analysis_profile_id' => $profile->id],
            [
                'feedback_count' => $counts['feedbackCount'],
                'relevant_count' => $counts['relevantCount'],
                'irrelevant_count' => $counts['irrelevantCount'],
                'adjustments' => $adjustments,
                'feature_statistics' => $statistics,
                'trained_at' => now(),
            ],
        );

        return $this->payload($settings, $profile, $personalization, $counts);
    }

    /** @return array<string, mixed> */
    public function reset(ApplicationSetting $settings): array
    {
        $profile = $this->latestProfile();
        if ($profile !== null) {
            AudioSimilarityPersonalization::query()
                ->where('audio_analysis_profile_id', $profile->id)
                ->delete();
        }
        $settings->update(['audio_similarity_personalization_enabled' => false]);

        return $this->status($settings->fresh());
    }

    /**
     * @param  array{enabled: bool, tempoInfluence: int|float, keyInfluence: int|float, intensityInfluence: int|float}  $preferences
     * @return array{preferences: array<string, int|float|bool>, personalization: array<string, mixed>}
     */
    public function apply(
        AudioAnalysisProfile $profile,
        array $preferences,
        bool $enabled,
    ): array {
        $personalization = AudioSimilarityPersonalization::query()
            ->where('audio_analysis_profile_id', $profile->id)
            ->first();
        $applied = $enabled && $preferences['enabled'] && $personalization !== null;
        $adjustments = $personalization?->adjustments ?? $this->emptyAdjustments();
        $effective = $preferences;
        if ($applied) {
            foreach ([
                'tempoInfluence' => 'tempo',
                'keyInfluence' => 'key',
                'intensityInfluence' => 'intensity',
            ] as $preference => $feature) {
                $effective[$preference] = round(max(
                    0,
                    min(10, $preferences[$preference] + ($adjustments[$feature] ?? 0)),
                ), 2);
            }
        }

        return [
            'preferences' => $effective,
            'personalization' => [
                'enabled' => $enabled,
                'applied' => $applied,
                'adjustments' => $adjustments,
                'trainedAt' => $personalization?->trained_at?->toAtomString(),
            ],
        ];
    }

    /**
     * @param  Collection<int, array{verdict: string, compatibility: array<string, ?float>}>  $samples
     * @return array{array<string, float>, array<string, array<string, int|float|null>>}
     */
    private function learn(Collection $samples): array
    {
        $adjustments = [];
        $statistics = [];
        foreach (['tempo', 'key', 'intensity'] as $feature) {
            $relevant = $this->compatibilityValues($samples, $feature, 'relevant');
            $irrelevant = $this->compatibilityValues($samples, $feature, 'irrelevant');
            $relevantMean = $relevant->isEmpty() ? null : (float) $relevant->average();
            $irrelevantMean = $irrelevant->isEmpty() ? null : (float) $irrelevant->average();
            $confidence = min(1, min($relevant->count(), $irrelevant->count()) / 10);
            $separation = $relevantMean === null || $irrelevantMean === null
                ? 0.0
                : $relevantMean - $irrelevantMean;
            $adjustments[$feature] = round(max(
                -self::MAXIMUM_ADJUSTMENT,
                min(self::MAXIMUM_ADJUSTMENT, $separation * 5 * $confidence),
            ), 2);
            $statistics[$feature] = [
                'relevantSampleCount' => $relevant->count(),
                'irrelevantSampleCount' => $irrelevant->count(),
                'relevantMean' => $relevantMean === null ? null : round($relevantMean, 4),
                'irrelevantMean' => $irrelevantMean === null ? null : round($irrelevantMean, 4),
            ];
        }

        return [$adjustments, $statistics];
    }

    /** @return Collection<int, float> */
    private function compatibilityValues(
        Collection $samples,
        string $feature,
        string $verdict,
    ): Collection {
        return $samples
            ->where('verdict', $verdict)
            ->pluck("compatibility.{$feature}")
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->values();
    }

    /** @return Collection<int, AudioSimilarityFeedback> */
    private function feedback(AudioAnalysisProfile $profile): Collection
    {
        return AudioSimilarityFeedback::query()
            ->where('audio_analysis_profile_id', $profile->id)
            ->latest('updated_at')
            ->get();
    }

    /** @return array{feedbackCount: int, relevantCount: int, irrelevantCount: int} */
    private function feedbackCounts(AudioAnalysisProfile $profile): array
    {
        $counts = AudioSimilarityFeedback::query()
            ->where('audio_analysis_profile_id', $profile->id)
            ->selectRaw('verdict, COUNT(*) AS aggregate')
            ->groupBy('verdict')
            ->pluck('aggregate', 'verdict');

        $relevantCount = (int) ($counts['relevant'] ?? 0);
        $irrelevantCount = (int) ($counts['irrelevant'] ?? 0);

        return [
            'feedbackCount' => $relevantCount + $irrelevantCount,
            'relevantCount' => $relevantCount,
            'irrelevantCount' => $irrelevantCount,
        ];
    }

    /**
     * @param  Collection<int, AudioSimilarityFeedback>  $feedback
     * @return array{feedbackCount: int, relevantCount: int, irrelevantCount: int}
     */
    private function verdictCounts(Collection $feedback): array
    {
        return [
            'feedbackCount' => $feedback->count(),
            'relevantCount' => $feedback->where('verdict', 'relevant')->count(),
            'irrelevantCount' => $feedback->where('verdict', 'irrelevant')->count(),
        ];
    }

    /** @return array{feedbackCount: int, relevantCount: int, irrelevantCount: int} */
    private function emptyVerdictCounts(): array
    {
        return [
            'feedbackCount' => 0,
            'relevantCount' => 0,
            'irrelevantCount' => 0,
        ];
    }

    /**
     * @param  list<int>  $trackIds
     * @return Collection<int, array<string, mixed>>
     */
    private function featuresForTracks(AudioAnalysisProfile $profile, array $trackIds): Collection
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
            ->whereIn('audio_analysis_run_items.track_id', $trackIds)
            ->groupBy('audio_analysis_run_items.track_id');

        return AudioAnalysisRunItem::query()
            ->whereIn('id', $latestItemIds)
            ->with('artifact:id,features')
            ->get()
            ->mapWithKeys(fn (AudioAnalysisRunItem $item): array => [
                $item->track_id => $item->artifact?->features ?? [],
            ]);
    }

    private function latestProfile(): ?AudioAnalysisProfile
    {
        return $this->profileSelector->current();
    }

    /** @param array{feedbackCount: int, relevantCount: int, irrelevantCount: int} $counts */
    private function canTrain(array $counts): bool
    {
        return $counts['feedbackCount'] >= self::MINIMUM_FEEDBACK_COUNT
            && $counts['relevantCount'] >= self::MINIMUM_VERDICT_COUNT
            && $counts['irrelevantCount'] >= self::MINIMUM_VERDICT_COUNT;
    }

    /**
     * @param  array{feedbackCount: int, relevantCount: int, irrelevantCount: int}  $counts
     * @return array<string, mixed>
     */
    private function payload(
        ApplicationSetting $settings,
        ?AudioAnalysisProfile $profile,
        ?AudioSimilarityPersonalization $personalization,
        array $counts,
    ): array {
        return [
            'enabled' => (bool) $settings->audio_similarity_personalization_enabled,
            'ready' => $personalization !== null,
            'applied' => $settings->audio_similarity_personalization_enabled
                && $personalization !== null
                && $settings->audio_similarity_reranking_enabled,
            'canTrain' => $this->canTrain($counts),
            'minimumFeedbackCount' => self::MINIMUM_FEEDBACK_COUNT,
            'minimumVerdictCount' => self::MINIMUM_VERDICT_COUNT,
            ...$counts,
            'profileId' => $profile?->id,
            'adjustments' => $personalization?->adjustments ?? $this->emptyAdjustments(),
            'featureStatistics' => $personalization?->feature_statistics ?? [],
            'trainedAt' => $personalization?->trained_at?->toAtomString(),
        ];
    }

    /** @return array{tempo: float, key: float, intensity: float} */
    private function emptyAdjustments(): array
    {
        return ['tempo' => 0.0, 'key' => 0.0, 'intensity' => 0.0];
    }
}
