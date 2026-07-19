<?php

namespace App\Music\Intelligence;

use App\Enums\MediaFileStatus;
use App\Models\AudioAnalysisRun;
use App\Music\Scanning\AudioContentFingerprinter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AudioIntelligencePilotSampler
{
    public const MINIMUM_SAMPLE_SIZE = 50;

    public const MAXIMUM_SAMPLE_SIZE = 500;

    /**
     * @return Collection<int, object{
     *     id: int,
     *     name: string,
     *     eligible_track_count: int,
     *     fingerprinted_track_count: int
     * }>
     */
    public function eligibleRoots(): Collection
    {
        return DB::table('library_roots')
            ->join('media_files', 'media_files.library_root_id', '=', 'library_roots.id')
            ->join('tracks', 'tracks.media_file_id', '=', 'media_files.id')
            ->where('library_roots.enabled', true)
            ->where('media_files.status', MediaFileStatus::Available->value)
            ->groupBy(['library_roots.id', 'library_roots.name'])
            ->orderBy('library_roots.id')
            ->get([
                'library_roots.id',
                'library_roots.name',
                DB::raw('COUNT(tracks.id)::int AS eligible_track_count'),
                DB::raw(
                    'COUNT(*) FILTER (WHERE media_files.content_fingerprint IS NOT NULL'
                    .' AND media_files.content_fingerprint_version = '
                    .AudioContentFingerprinter::VERSION.')::int AS fingerprinted_track_count',
                ),
            ]);
    }

    public function eligibleTrackCount(): int
    {
        return $this->coverage()['eligibleTrackCount'];
    }

    /**
     * @return array{
     *     eligibleTrackCount: int,
     *     fingerprintedTrackCount: int,
     *     eligibleRootCount: int
     * }
     */
    public function coverage(): array
    {
        return Cache::remember(
            'sonotheque:audio-intelligence:fingerprint-coverage:v1',
            300,
            function (): array {
                $roots = $this->eligibleRoots();

                return [
                    'eligibleTrackCount' => (int) $roots->sum('eligible_track_count'),
                    'fingerprintedTrackCount' => (int) $roots->sum('fingerprinted_track_count'),
                    'eligibleRootCount' => $roots->count(),
                ];
            },
        );
    }

    public function forgetCoverage(): void
    {
        Cache::forget('sonotheque:audio-intelligence:fingerprint-coverage:v1');
    }

    public function prepare(int $requestedTrackCount): AudioAnalysisRun
    {
        $roots = $this->eligibleRoots();
        $eligibleTrackCount = (int) $roots->sum('eligible_track_count');
        $targetTrackCount = min($requestedTrackCount, $eligibleTrackCount);
        $reserveTrackCount = min(
            max(0, $eligibleTrackCount - $targetTrackCount),
            max(10, (int) ceil($targetTrackCount * 0.1)),
        );
        $seed = Str::uuid()->toString();

        $selected = $targetTrackCount === 0
            ? collect()
            : $this->selectTracks($roots, $targetTrackCount + $reserveTrackCount, $seed);

        return DB::transaction(function () use (
            $eligibleTrackCount,
            $requestedTrackCount,
            $roots,
            $seed,
            $selected,
        ): AudioAnalysisRun {
            $run = AudioAnalysisRun::create([
                'phase' => 'preparation',
                'status' => 'fingerprinting',
                'selection_seed' => $seed,
                'requested_track_count' => $requestedTrackCount,
                'selected_track_count' => 0,
                'summary' => [
                    'eligibleTrackCount' => $eligibleTrackCount,
                    'eligibleRootCount' => $roots->count(),
                    'candidateTrackCount' => $selected->count(),
                    'candidateRootCount' => $selected->pluck('library_root_id')->unique()->count(),
                    'candidateGenreCount' => $selected->pluck('genre_id')->filter()->unique()->count(),
                    'candidateArtistCount' => $selected->pluck('artist_id')->filter()->unique()->count(),
                    'fingerprintedTrackCount' => 0,
                    'fingerprintFailedTrackCount' => 0,
                    'processedFingerprintTrackCount' => 0,
                ],
            ]);

            $now = now();
            $items = $selected->values()->map(
                fn (object $track, int $position): array => [
                    'audio_analysis_run_id' => $run->id,
                    'track_id' => $track->track_id,
                    'library_root_id' => $track->library_root_id,
                    'genre_id' => $track->genre_id,
                    'content_fingerprint' => null,
                    'content_fingerprint_version' => null,
                    'position' => $position,
                    'status' => 'pending_fingerprint',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            if ($items->isNotEmpty()) {
                DB::table('audio_analysis_run_items')->insert($items->all());
            }

            return $run;
        });
    }

    /**
     * @param  Collection<int, object{
     *     id: int,
     *     name: string,
     *     eligible_track_count: int,
     *     fingerprinted_track_count: int
     * }>  $roots
     * @return Collection<int, object{
     *     track_id: int,
     *     library_root_id: int,
     *     genre_id: ?int,
     *     artist_id: ?int
     * }>
     */
    private function selectTracks(Collection $roots, int $targetTrackCount, string $seed): Collection
    {
        $targets = $this->allocateRootTargets($roots, $targetTrackCount);

        return $roots
            ->flatMap(function (object $root) use ($seed, $targets): Collection {
                $rootTarget = $targets[$root->id] ?? 0;
                if ($rootTarget === 0) {
                    return collect();
                }

                $candidateCount = min(
                    $root->eligible_track_count,
                    max($rootTarget * 12, 100),
                );
                $candidates = DB::table('tracks')
                    ->join('media_files', 'media_files.id', '=', 'tracks.media_file_id')
                    ->join('albums', 'albums.id', '=', 'tracks.album_id')
                    ->select([
                        'tracks.id as track_id',
                        'media_files.library_root_id',
                        'albums.primary_artist_id as album_artist_id',
                    ])
                    ->selectSub(
                        DB::table('genre_track')
                            ->selectRaw('MIN(genre_id)')
                            ->whereColumn('genre_track.track_id', 'tracks.id'),
                        'genre_id',
                    )
                    ->selectSub(
                        DB::table('artist_track')
                            ->selectRaw('MIN(artist_id)')
                            ->whereColumn('artist_track.track_id', 'tracks.id'),
                        'track_artist_id',
                    )
                    ->where('media_files.library_root_id', $root->id)
                    ->where('media_files.status', MediaFileStatus::Available->value)
                    ->orderByRaw(
                        "md5(media_files.relative_path_hash || ? || tracks.id::text)",
                        [$seed],
                    )
                    ->limit($candidateCount)
                    ->get()
                    ->each(function (object $candidate): void {
                        $candidate->artist_id = $candidate->track_artist_id
                            ?? $candidate->album_artist_id;
                    });

                return $this->takeDiverse($candidates, $rootTarget, $seed);
            })
            ->values();
    }

    /**
     * @param  Collection<int, object{
     *     id: int,
     *     name: string,
     *     eligible_track_count: int
     * }>  $roots
     * @return array<int, int>
     */
    private function allocateRootTargets(Collection $roots, int $targetTrackCount): array
    {
        if ($roots->isEmpty() || $targetTrackCount === 0) {
            return [];
        }

        $targets = $roots->mapWithKeys(fn (object $root): array => [$root->id => 0])->all();
        $remaining = $targetTrackCount;

        if ($targetTrackCount >= $roots->count()) {
            foreach ($roots as $root) {
                $targets[$root->id] = 1;
                $remaining--;
            }
        } else {
            foreach ($roots->sortByDesc('eligible_track_count')->take($targetTrackCount) as $root) {
                $targets[$root->id] = 1;
                $remaining--;
            }
        }

        if ($remaining === 0) {
            return $targets;
        }

        $capacities = $roots->mapWithKeys(
            fn (object $root): array => [
                $root->id => max(0, $root->eligible_track_count - $targets[$root->id]),
            ],
        );
        $eligibleTrackCount = max(1, (int) $capacities->sum());
        $remainders = [];
        $allocated = 0;

        foreach ($roots as $root) {
            $capacity = $capacities[$root->id];
            $exact = $remaining * $capacity / $eligibleTrackCount;
            $whole = min($capacity, (int) floor($exact));
            $targets[$root->id] += $whole;
            $allocated += $whole;
            $remainders[$root->id] = $exact - $whole;
        }

        arsort($remainders);
        foreach (array_keys($remainders) as $rootId) {
            if ($allocated >= $remaining) {
                break;
            }
            $root = $roots->firstWhere('id', $rootId);
            if ($root === null || $targets[$rootId] >= $root->eligible_track_count) {
                continue;
            }

            $targets[$rootId]++;
            $allocated++;
        }

        return $targets;
    }

    /**
     * @param  Collection<int, object{
     *     track_id: int,
     *     library_root_id: int,
     *     genre_id: ?int,
     *     artist_id: ?int
     * }>  $candidates
     * @return Collection<int, object{
     *     track_id: int,
     *     library_root_id: int,
     *     genre_id: ?int,
     *     artist_id: ?int
     * }>
     */
    private function takeDiverse(Collection $candidates, int $limit, string $seed): Collection
    {
        /** @var array<string, list<object>> $groups */
        $groups = [];
        foreach ($candidates as $candidate) {
            $groups[(string) ($candidate->genre_id ?? 'unclassified')][] = $candidate;
        }

        uksort(
            $groups,
            fn (string $left, string $right): int => strcmp(
                hash('sha256', $seed.':'.$left),
                hash('sha256', $seed.':'.$right),
            ),
        );

        $selected = collect();
        $selectedArtists = [];
        while ($selected->count() < $limit && $groups !== []) {
            foreach (array_keys($groups) as $key) {
                $candidateIndex = null;
                foreach ($groups[$key] as $index => $groupCandidate) {
                    $artistKey = $groupCandidate->artist_id === null
                        ? null
                        : (string) $groupCandidate->artist_id;
                    if ($artistKey === null || ! isset($selectedArtists[$artistKey])) {
                        $candidateIndex = $index;
                        break;
                    }
                }
                $candidateIndex ??= 0;
                $candidate = array_splice($groups[$key], $candidateIndex, 1)[0] ?? null;
                if ($candidate !== null) {
                    $selected->push($candidate);
                    if ($candidate->artist_id !== null) {
                        $selectedArtists[(string) $candidate->artist_id] = true;
                    }
                }
                if ($groups[$key] === []) {
                    unset($groups[$key]);
                }
                if ($selected->count() >= $limit) {
                    break;
                }
            }
        }

        return $selected;
    }
}
