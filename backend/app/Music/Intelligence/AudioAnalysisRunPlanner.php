<?php

namespace App\Music\Intelligence;

use App\Enums\MediaFileStatus;
use App\Models\AudioAnalysisArtifact;
use App\Models\AudioAnalysisProfile;
use App\Models\AudioAnalysisRun;
use App\Models\LibraryRoot;
use App\Music\Scanning\AudioContentFingerprinter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AudioAnalysisRunPlanner
{
    public const MINIMUM_VALIDATION_SAMPLE_SIZE = 50;

    public const MAXIMUM_VALIDATION_SAMPLE_SIZE = 500;

    public const MAXIMUM_REVIEW_POOL_SIZE = 500;

    /**
     * @return Collection<int, object{
     *     id: int,
     *     name: string,
     *     eligible_track_count: int,
     *     fingerprinted_track_count: int
     * }>
     */
    public function eligibleRoots(?int $libraryRootId = null): Collection
    {
        return DB::table('library_roots')
            ->join('media_files', 'media_files.library_root_id', '=', 'library_roots.id')
            ->join('tracks', 'tracks.media_file_id', '=', 'media_files.id')
            ->where('library_roots.enabled', true)
            ->where('media_files.status', MediaFileStatus::Available->value)
            ->when(
                $libraryRootId !== null,
                fn ($query) => $query->where('library_roots.id', $libraryRootId),
            )
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

    public function prepareValidationSample(int $requestedTrackCount): AudioAnalysisRun
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

        return $this->createRun(
            $eligibleTrackCount,
            $requestedTrackCount,
            $roots,
            $seed,
            $selected,
        );
    }

    public function analyzedTrackCount(
        AudioAnalysisProfile $profile,
        ?int $libraryRootId = null,
    ): int {
        return DB::table('audio_analysis_run_items as items')
            ->join('audio_analysis_artifacts as artifacts', 'artifacts.id', '=', 'items.audio_analysis_artifact_id')
            ->join('tracks', 'tracks.id', '=', 'items.track_id')
            ->join('media_files', 'media_files.id', '=', 'tracks.media_file_id')
            ->join('library_roots', 'library_roots.id', '=', 'media_files.library_root_id')
            ->where('artifacts.audio_analysis_profile_id', $profile->id)
            ->whereIn('items.status', ['completed', 'reused'])
            ->where('library_roots.enabled', true)
            ->where('media_files.status', MediaFileStatus::Available->value)
            ->when(
                $libraryRootId !== null,
                fn ($query) => $query->where('media_files.library_root_id', $libraryRootId),
            )
            ->distinct()
            ->count('tracks.id');
    }

    public function prepareExpansion(
        int $requestedTrackCount,
        AudioAnalysisProfile $profile,
    ): AudioAnalysisRun {
        $roots = $this->eligibleRoots();
        $eligibleTrackCount = (int) $roots->sum('eligible_track_count');
        $targetTrackCount = min($requestedTrackCount, $eligibleTrackCount);
        $existing = $this->analyzedTracks($profile);
        $existingTrackCount = $existing->count();

        if ($targetTrackCount <= $existingTrackCount) {
            throw new \InvalidArgumentException(
                'The expansion target must exceed the current analyzed track count.',
            );
        }

        $additionalTrackCount = $targetTrackCount - $existingTrackCount;
        $availableRoots = $this->rootsWithoutExistingTracks($roots, $existing);
        $availableTrackCount = (int) $availableRoots->sum('eligible_track_count');
        $reserveTrackCount = min(
            max(0, $availableTrackCount - $additionalTrackCount),
            max(10, (int) ceil($additionalTrackCount * 0.1)),
        );
        $seed = Str::uuid()->toString();
        $additional = $this->selectTracks(
            $availableRoots,
            $additionalTrackCount + $reserveTrackCount,
            $seed,
            $existing->pluck('track_id')->all(),
        );
        $selected = $existing->concat($additional)->values();

        return $this->createRun(
            $eligibleTrackCount,
            $requestedTrackCount,
            $roots,
            $seed,
            $selected,
            $profile,
            [
                'mode' => 'expansion',
                'baselineAnalyzedTrackCount' => $existingTrackCount,
                'newTrackTargetCount' => $additionalTrackCount,
            ],
            'expansion',
        );
    }

    public function prepareCollection(
        AudioAnalysisProfile $profile,
        ?LibraryRoot $libraryRoot = null,
    ): AudioAnalysisRun {
        $roots = $this->eligibleRoots($libraryRoot?->id);
        $eligibleTrackCount = (int) $roots->sum('eligible_track_count');

        if ($eligibleTrackCount === 0) {
            throw new \InvalidArgumentException(
                'The selected collection scope has no eligible tracks.',
            );
        }

        return AudioAnalysisRun::create([
            'audio_analysis_profile_id' => $profile->id,
            'library_root_id' => $libraryRoot?->id,
            'phase' => 'preparation',
            'kind' => 'collection',
            'status' => 'fingerprinting',
            'selection_seed' => Str::uuid()->toString(),
            'requested_track_count' => $eligibleTrackCount,
            'selected_track_count' => 0,
            'summary' => [
                'mode' => 'collection',
                'eligibleTrackCount' => $eligibleTrackCount,
                'eligibleRootCount' => $roots->count(),
                'candidateTrackCount' => 0,
                'candidateRootCount' => $roots->count(),
                'baselineAnalyzedTrackCount' => $this->analyzedTrackCount(
                    $profile,
                    $libraryRoot?->id,
                ),
                'candidatesEnumerated' => false,
                'lastEnumeratedTrackId' => 0,
                'fingerprintedTrackCount' => 0,
                'fingerprintFailedTrackCount' => 0,
                'processedFingerprintTrackCount' => 0,
            ],
        ]);
    }

    public function populateCollectionRun(AudioAnalysisRun $run): bool
    {
        if ($run->kind !== 'collection') {
            return true;
        }

        $lastEnumeratedTrackId = (int) ($run->summary['lastEnumeratedTrackId'] ?? 0);
        DB::table('tracks')
            ->join('media_files', 'media_files.id', '=', 'tracks.media_file_id')
            ->join('library_roots', 'library_roots.id', '=', 'media_files.library_root_id')
            ->select([
                'tracks.id as track_id',
                'media_files.library_root_id',
                'media_files.content_fingerprint',
                'media_files.content_fingerprint_version',
            ])
            ->selectSub(
                DB::table('genre_track')
                    ->selectRaw('MIN(genre_id)')
                    ->whereColumn('genre_track.track_id', 'tracks.id'),
                'genre_id',
            )
            ->where('library_roots.enabled', true)
            ->where('media_files.status', MediaFileStatus::Available->value)
            ->where('tracks.id', '>', $lastEnumeratedTrackId)
            ->when(
                $run->library_root_id !== null,
                fn ($query) => $query->where(
                    'media_files.library_root_id',
                    $run->library_root_id,
                ),
            )
            ->orderBy('tracks.id')
            ->chunkById(
                1000,
                function (Collection $tracks) use ($run): bool {
                    $now = now();
                    $artifacts = AudioAnalysisArtifact::query()
                        ->where('audio_analysis_profile_id', $run->audio_analysis_profile_id)
                        ->whereIn(
                            'content_fingerprint',
                            $tracks->pluck('content_fingerprint')->filter()->unique(),
                        )
                        ->get()
                        ->keyBy(
                            fn (AudioAnalysisArtifact $artifact): string => $artifact
                                ->content_fingerprint_version.':'.$artifact->content_fingerprint,
                        );
                    $items = $tracks->map(function (object $track) use (
                        $artifacts,
                        $now,
                        $run,
                    ): array {
                        $hasCurrentFingerprint = $track->content_fingerprint !== null
                            && $track->content_fingerprint_version === AudioContentFingerprinter::VERSION;
                        $artifact = $hasCurrentFingerprint
                            ? $artifacts->get(
                                $track->content_fingerprint_version
                                    .':'.$track->content_fingerprint,
                            )
                            : null;

                        return [
                            'audio_analysis_run_id' => $run->id,
                            'track_id' => $track->track_id,
                            'library_root_id' => $track->library_root_id,
                            'genre_id' => $track->genre_id,
                            'audio_analysis_artifact_id' => $artifact?->id,
                            'content_fingerprint' => $hasCurrentFingerprint
                                ? $track->content_fingerprint
                                : null,
                            'content_fingerprint_version' => $hasCurrentFingerprint
                                ? $track->content_fingerprint_version
                                : null,
                            'position' => $track->track_id,
                            'status' => match (true) {
                                $artifact !== null => 'reused',
                                $hasCurrentFingerprint => 'selected',
                                default => 'pending_fingerprint',
                            },
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    });

                    DB::table('audio_analysis_run_items')->insertOrIgnore($items->all());
                    $run->refresh();
                    $summary = $run->summary ?? [];
                    $summary['candidateTrackCount'] = $run->items()->count();
                    $summary['lastEnumeratedTrackId'] = (int) $tracks->max('track_id');
                    $run->update([
                        'summary' => $summary,
                        'heartbeat_at' => now(),
                    ]);

                    return $run->pause_requested_at === null
                        && $run->cancel_requested_at === null;
                },
                'tracks.id',
                'track_id',
            );

        $run->refresh();
        if ($run->pause_requested_at !== null || $run->cancel_requested_at !== null) {
            return false;
        }

        $summary = $run->summary ?? [];
        $summary['candidateTrackCount'] = $run->items()->count();
        $summary['candidatesEnumerated'] = true;
        $run->update([
            'summary' => $summary,
            'heartbeat_at' => now(),
        ]);

        return true;
    }

    /**
     * @param  Collection<int, object>  $roots
     * @param  Collection<int, object>  $selected
     * @param  array<string, mixed>  $summary
     */
    private function createRun(
        int $eligibleTrackCount,
        int $requestedTrackCount,
        Collection $roots,
        string $seed,
        Collection $selected,
        ?AudioAnalysisProfile $profile = null,
        array $summary = [],
        string $kind = 'validation',
    ): AudioAnalysisRun {
        return DB::transaction(function () use (
            $eligibleTrackCount,
            $profile,
            $requestedTrackCount,
            $roots,
            $seed,
            $selected,
            $summary,
            $kind,
        ): AudioAnalysisRun {
            $run = AudioAnalysisRun::create([
                'audio_analysis_profile_id' => $profile?->id,
                'phase' => 'preparation',
                'kind' => $kind,
                'status' => 'fingerprinting',
                'selection_seed' => $seed,
                'requested_track_count' => $requestedTrackCount,
                'selected_track_count' => 0,
                'summary' => array_merge([
                    'eligibleTrackCount' => $eligibleTrackCount,
                    'eligibleRootCount' => $roots->count(),
                    'candidateTrackCount' => $selected->count(),
                    'candidateRootCount' => $selected->pluck('library_root_id')->unique()->count(),
                    'candidateGenreCount' => $selected->pluck('genre_id')->filter()->unique()->count(),
                    'candidateArtistCount' => $selected->pluck('artist_id')->filter()->unique()->count(),
                    'fingerprintedTrackCount' => 0,
                    'fingerprintFailedTrackCount' => 0,
                    'processedFingerprintTrackCount' => 0,
                ], $summary),
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
    private function selectTracks(
        Collection $roots,
        int $targetTrackCount,
        string $seed,
        array $excludedTrackIds = [],
    ): Collection {
        $targets = $this->allocateRootTargets($roots, $targetTrackCount);

        return $roots
            ->flatMap(function (object $root) use ($excludedTrackIds, $seed, $targets): Collection {
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
                    ->when(
                        $excludedTrackIds !== [],
                        fn ($query) => $query->whereNotIn('tracks.id', $excludedTrackIds),
                    )
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

    /** @return Collection<int, object> */
    private function analyzedTracks(
        AudioAnalysisProfile $profile,
        ?int $libraryRootId = null,
    ): Collection {
        return DB::table('audio_analysis_run_items as items')
            ->join('audio_analysis_artifacts as artifacts', 'artifacts.id', '=', 'items.audio_analysis_artifact_id')
            ->join('tracks', 'tracks.id', '=', 'items.track_id')
            ->join('media_files', 'media_files.id', '=', 'tracks.media_file_id')
            ->join('library_roots', 'library_roots.id', '=', 'media_files.library_root_id')
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
            ->where('artifacts.audio_analysis_profile_id', $profile->id)
            ->whereIn('items.status', ['completed', 'reused'])
            ->where('library_roots.enabled', true)
            ->where('media_files.status', MediaFileStatus::Available->value)
            ->when(
                $libraryRootId !== null,
                fn ($query) => $query->where('media_files.library_root_id', $libraryRootId),
            )
            ->latest('items.id')
            ->get()
            ->unique('track_id')
            ->each(function (object $track): void {
                $track->artist_id = $track->track_artist_id ?? $track->album_artist_id;
            })
            ->values();
    }

    /**
     * @param  Collection<int, object>  $roots
     * @param  Collection<int, object>  $existing
     * @return Collection<int, object>
     */
    private function rootsWithoutExistingTracks(Collection $roots, Collection $existing): Collection
    {
        $existingByRoot = $existing->countBy('library_root_id');

        return $roots
            ->map(function (object $root) use ($existingByRoot): object {
                $availableRoot = clone $root;
                $availableRoot->eligible_track_count = max(
                    0,
                    $root->eligible_track_count - (int) $existingByRoot->get($root->id, 0),
                );

                return $availableRoot;
            })
            ->filter(fn (object $root): bool => $root->eligible_track_count > 0)
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
