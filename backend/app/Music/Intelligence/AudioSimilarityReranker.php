<?php

namespace App\Music\Intelligence;

use Illuminate\Support\Collection;

class AudioSimilarityReranker
{
    private const MAXIMUM_CANDIDATE_POOL = 100;

    private const PITCH_CLASSES = [
        'C' => 0,
        'B#' => 0,
        'C#' => 1,
        'DB' => 1,
        'D' => 2,
        'D#' => 3,
        'EB' => 3,
        'E' => 4,
        'FB' => 4,
        'E#' => 5,
        'F' => 5,
        'F#' => 6,
        'GB' => 6,
        'G' => 7,
        'G#' => 8,
        'AB' => 8,
        'A' => 9,
        'A#' => 10,
        'BB' => 10,
        'B' => 11,
        'CB' => 11,
    ];

    /**
     * @param  array{enabled: bool, tempoInfluence: int|float, keyInfluence: int|float, intensityInfluence: int|float}  $preferences
     */
    public function candidateLimit(int $requestedLimit, array $preferences): int
    {
        if (! $this->enabled($preferences)) {
            return $requestedLimit;
        }

        return min(self::MAXIMUM_CANDIDATE_POOL, max($requestedLimit, $requestedLimit * 10));
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  Collection<int, array<string, mixed>>  $matches
     * @param  array{enabled: bool, tempoInfluence: int|float, keyInfluence: int|float, intensityInfluence: int|float}  $preferences
     * @return Collection<int, array<string, mixed>>
     */
    public function rerank(array $source, Collection $matches, array $preferences): Collection
    {
        $enabled = $this->enabled($preferences);

        return $matches
            ->map(function (array $match) use ($enabled, $preferences, $source): array {
                $compatibility = $this->compatibilities($source, $match);
                $score = (float) $match['similarity'];
                if ($enabled) {
                    foreach ([
                        'tempo' => 'tempoInfluence',
                        'key' => 'keyInfluence',
                        'intensity' => 'intensityInfluence',
                    ] as $feature => $influence) {
                        if ($compatibility[$feature] !== null) {
                            $score -= ($preferences[$influence] / 100)
                                * (1 - $compatibility[$feature]);
                        }
                    }
                }

                return [
                    ...$match,
                    'rankingScore' => round(max(-1, min(1, $score)), 6),
                    'featureCompatibility' => collect($compatibility)
                        ->map(fn (?float $value): ?float => $value === null
                            ? null
                            : round($value, 4))
                        ->all(),
                ];
            })
            ->when(
                $enabled,
                fn (Collection $ranked): Collection => $ranked->sort(function (
                    array $left,
                    array $right,
                ): int {
                    $rankingComparison = $right['rankingScore'] <=> $left['rankingScore'];

                    return $rankingComparison !== 0
                        ? $rankingComparison
                        : $right['similarity'] <=> $left['similarity'];
                }),
            )
            ->values();
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $candidate
     * @return array{tempo: ?float, key: ?float, intensity: ?float}
     */
    public function compatibilities(array $source, array $candidate): array
    {
        return [
            'tempo' => $this->tempoCompatibility(
                $source['features']['bpm'] ?? null,
                $candidate['features']['bpm'] ?? null,
            ),
            'key' => $this->keyCompatibility(
                $source['features'] ?? [],
                $candidate['features'] ?? [],
            ),
            'intensity' => $this->intensityCompatibility(
                $source['features'] ?? [],
                $candidate['features'] ?? [],
            ),
        ];
    }

    /**
     * @param  array{enabled: bool, tempoInfluence: int|float, keyInfluence: int|float, intensityInfluence: int|float}  $preferences
     */
    private function enabled(array $preferences): bool
    {
        return $preferences['enabled'] && collect([
            $preferences['tempoInfluence'],
            $preferences['keyInfluence'],
            $preferences['intensityInfluence'],
        ])->contains(fn (int|float $influence): bool => $influence > 0);
    }

    private function tempoCompatibility(mixed $source, mixed $candidate): ?float
    {
        if (! is_numeric($source) || ! is_numeric($candidate)
            || (float) $source <= 0 || (float) $candidate <= 0) {
            return null;
        }

        $octaves = abs(log((float) $candidate / (float) $source, 2));
        $octaveInvariantDistance = abs($octaves - round($octaves));

        return max(0, 1 - ($octaveInvariantDistance / 0.5));
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $candidate */
    private function keyCompatibility(array $source, array $candidate): ?float
    {
        $sourcePitch = $this->pitchClass($source['key'] ?? null);
        $candidatePitch = $this->pitchClass($candidate['key'] ?? null);
        if ($sourcePitch === null || $candidatePitch === null) {
            return null;
        }

        $sourceScale = $this->scale($source['scale'] ?? null);
        $candidateScale = $this->scale($candidate['scale'] ?? null);
        if ($sourcePitch === $candidatePitch && $sourceScale === $candidateScale) {
            return 1;
        }
        if ($sourceScale !== null && $candidateScale !== null && $sourceScale !== $candidateScale) {
            $minorPitch = $sourceScale === 'minor' ? $sourcePitch : $candidatePitch;
            $majorPitch = $sourceScale === 'major' ? $sourcePitch : $candidatePitch;
            if (($minorPitch + 3) % 12 === $majorPitch) {
                return 0.95;
            }
        }

        $distance = abs($sourcePitch - $candidatePitch);
        $distance = min($distance, 12 - $distance);
        $compatibility = 1 - ($distance / 6);

        return $sourceScale !== null && $candidateScale !== null && $sourceScale !== $candidateScale
            ? $compatibility * 0.75
            : $compatibility;
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $candidate */
    private function intensityCompatibility(array $source, array $candidate): ?float
    {
        $values = collect([
            $this->ratioCompatibility($source['loudness'] ?? null, $candidate['loudness'] ?? null, 2),
            $this->ratioCompatibility(
                $source['danceability'] ?? null,
                $candidate['danceability'] ?? null,
                1.5,
            ),
            $this->ratioCompatibility(
                $source['dynamicComplexity'] ?? null,
                $candidate['dynamicComplexity'] ?? null,
                2.5,
            ),
        ])->filter(fn (?float $value): bool => $value !== null);

        return $values->isEmpty() ? null : (float) $values->average();
    }

    private function ratioCompatibility(mixed $source, mixed $candidate, float $range): ?float
    {
        if (! is_numeric($source) || ! is_numeric($candidate)
            || (float) $source <= 0 || (float) $candidate <= 0) {
            return null;
        }

        return max(0, 1 - (abs(log((float) $candidate / (float) $source, 2)) / $range));
    }

    private function pitchClass(mixed $key): ?int
    {
        if (! is_string($key)) {
            return null;
        }

        return self::PITCH_CLASSES[strtoupper(trim($key))] ?? null;
    }

    private function scale(mixed $scale): ?string
    {
        if (! is_string($scale)) {
            return null;
        }

        $normalized = strtolower(trim($scale));

        return in_array($normalized, ['major', 'minor'], true) ? $normalized : null;
    }
}
