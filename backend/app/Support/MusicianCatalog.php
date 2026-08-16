<?php

namespace App\Support;

use App\Enums\MediaFileStatus;
use App\Enums\OnlineContentStatus;
use App\Models\Album;
use App\Models\Musician;
use App\Music\Enrichment\AlbumMusicianCreditManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MusicianCatalog
{
    public function __construct(private readonly LibraryRootScope $libraryRootScope)
    {
    }

    /** @return EloquentBuilder<Musician> */
    public function query(?int $libraryRootId): EloquentBuilder
    {
        $albumCounts = DB::query()
            ->fromSub($this->effectiveAlbumPairs($libraryRootId), 'effective_musician_albums')
            ->select('musician_id')
            ->selectRaw('count(*) as album_count')
            ->groupBy('musician_id');
        $trackCounts = DB::query()
            ->fromSub($this->effectiveTrackPairs($libraryRootId), 'effective_musician_tracks')
            ->select('musician_id')
            ->selectRaw('count(*) as track_count')
            ->groupBy('musician_id');

        return Musician::query()
            ->joinSub(
                $albumCounts,
                'musician_album_counts',
                fn ($join) => $join->on('musician_album_counts.musician_id', '=', 'musicians.id'),
            )
            ->leftJoinSub(
                $trackCounts,
                'musician_track_counts',
                fn ($join) => $join->on('musician_track_counts.musician_id', '=', 'musicians.id'),
            )
            ->select('musicians.*')
            ->selectRaw('musician_album_counts.album_count as album_count')
            ->selectRaw('coalesce(musician_track_counts.track_count, 0) as track_count');
    }

    public function count(?int $libraryRootId): int
    {
        return $this->query($libraryRootId)->count();
    }

    /** @return array{checkedAlbums: int, creditedAlbums: int, totalAlbums: int, percentage: int} */
    public function coverage(?int $libraryRootId): array
    {
        $albums = $this->libraryRootScope
            ->albums(Album::query(), $libraryRootId)
            ->has('tracks');
        $totalAlbums = (clone $albums)->count();
        $checkedAlbums = (clone $albums)
            ->whereHas('musicianEnrichment', fn (EloquentBuilder $enrichments) => $enrichments
                ->where('lookup_version', AlbumMusicianCreditManager::LOOKUP_VERSION)
                ->whereIn('status', [
                    OnlineContentStatus::Ready->value,
                    OnlineContentStatus::NotFound->value,
                    OnlineContentStatus::Ambiguous->value,
                ]))
            ->count();
        $creditedAlbums = DB::query()
            ->fromSub($this->effectiveAlbumPairs($libraryRootId), 'credited_albums')
            ->distinct()
            ->count('album_id');

        return [
            'checkedAlbums' => $checkedAlbums,
            'creditedAlbums' => $creditedAlbums,
            'totalAlbums' => $totalAlbums,
            'percentage' => $totalAlbums === 0
                ? 0
                : (int) round(($checkedAlbums / $totalAlbums) * 100),
        ];
    }

    public function albumIdsForMusician(int $musicianId): QueryBuilder
    {
        return DB::query()
            ->fromSub($this->effectiveAlbumPairs(null, $musicianId, scopeLibraryRoots: false), 'musician_albums')
            ->select('album_id');
    }

    public function trackIdsForMusician(int $musicianId): QueryBuilder
    {
        return DB::query()
            ->fromSub($this->effectiveTrackPairs(null, $musicianId, scopeLibraryRoots: false), 'musician_tracks')
            ->select('track_id');
    }

    /**
     * @return array{
     *     roles: list<array{name: string, albumCount: int, trackCount: int}>,
     *     creditedAs: list<string>,
     *     sources: list<string>,
     *     firstReleaseYear: int|null,
     *     lastReleaseYear: int|null
     * }
     */
    public function profile(Musician $musician, ?int $libraryRootId): array
    {
        $credits = $this->effectiveCredits($libraryRootId, $musician->id)->get();
        $albumIds = $credits->pluck('album_id')->unique()->values();
        $years = Album::query()
            ->whereIn('id', $albumIds)
            ->whereNotNull('original_release_year');

        return [
            'roles' => $this->roleSummaries($credits),
            'creditedAs' => $this->uniqueText(
                $credits->pluck('credited_as')
                    ->filter(fn (mixed $name): bool => is_string($name)
                        && trim($name) !== ''
                        && mb_strtolower(trim($name)) !== mb_strtolower($musician->name)),
            ),
            'sources' => $this->uniqueText($credits->pluck('provider')),
            'firstReleaseYear' => (clone $years)->min('original_release_year'),
            'lastReleaseYear' => (clone $years)->max('original_release_year'),
        ];
    }

    /**
     * @param list<int> $albumIds
     * @return array<int, array{
     *     roles: list<string>,
     *     creditedAs: list<string>,
     *     sources: list<string>,
     *     albumWide: bool,
     *     trackCreditCount: int,
     *     guest: bool,
     *     additional: bool
     * }>
     */
    public function albumCreditSummaries(
        int $musicianId,
        array $albumIds,
        ?int $libraryRootId,
    ): array {
        if ($albumIds === []) {
            return [];
        }

        return $this->effectiveCredits($libraryRootId, $musicianId, $albumIds)
            ->get()
            ->groupBy('album_id')
            ->map(function (Collection $credits): array {
                return [
                    'roles' => $this->uniqueText($credits->pluck('role')),
                    'creditedAs' => $this->uniqueText($credits->pluck('credited_as')),
                    'sources' => $this->uniqueText($credits->pluck('provider')),
                    'albumWide' => $credits->contains(fn (object $credit): bool => $credit->track_id === null),
                    'trackCreditCount' => $credits->pluck('track_id')->filter()->unique()->count(),
                    'guest' => $credits->contains(fn (object $credit): bool => (bool) $credit->is_guest),
                    'additional' => $credits->contains(fn (object $credit): bool => (bool) $credit->is_additional),
                ];
            })
            ->all();
    }

    private function effectiveAlbumPairs(
        ?int $libraryRootId,
        ?int $musicianId = null,
        bool $scopeLibraryRoots = true,
    ): QueryBuilder {
        $imported = DB::table('album_musician_credits as imported_credits')
            ->join('albums as imported_albums', 'imported_albums.id', '=', 'imported_credits.album_id')
            ->select(['imported_credits.musician_id', 'imported_credits.album_id'])
            ->when($musicianId, fn (QueryBuilder $query, int $id) => $query->where('imported_credits.musician_id', $id))
            ->whereNotExists(fn (QueryBuilder $suppressions) => $suppressions
                ->selectRaw('1')
                ->from('album_musician_credit_suppressions as suppressions')
                ->whereColumn('suppressions.album_id', 'imported_credits.album_id')
                ->whereColumn('suppressions.provider', 'imported_credits.provider')
                ->whereColumn('suppressions.source_credit_key', 'imported_credits.source_credit_key'));
        $manual = DB::table('manual_album_musician_credits as manual_credits')
            ->join('albums as manual_albums', 'manual_albums.id', '=', 'manual_credits.album_id')
            ->select(['manual_credits.musician_id', 'manual_credits.album_id'])
            ->when($musicianId, fn (QueryBuilder $query, int $id) => $query->where('manual_credits.musician_id', $id));

        if ($scopeLibraryRoots) {
            $this->scopeAlbums($imported, 'imported_albums', $libraryRootId);
            $this->scopeAlbums($manual, 'manual_albums', $libraryRootId);
        }

        return $imported->union($manual);
    }

    private function effectiveTrackPairs(
        ?int $libraryRootId,
        ?int $musicianId = null,
        bool $scopeLibraryRoots = true,
    ): QueryBuilder {
        $imported = DB::table('album_musician_credits as imported_credits')
            ->join('albums as imported_albums', 'imported_albums.id', '=', 'imported_credits.album_id')
            ->join('tracks as imported_tracks', 'imported_tracks.id', '=', 'imported_credits.track_id')
            ->join('media_files as imported_files', 'imported_files.id', '=', 'imported_tracks.media_file_id')
            ->select(['imported_credits.musician_id', 'imported_credits.track_id'])
            ->where('imported_files.status', MediaFileStatus::Available->value)
            ->when($musicianId, fn (QueryBuilder $query, int $id) => $query->where('imported_credits.musician_id', $id))
            ->whereNotExists(fn (QueryBuilder $suppressions) => $suppressions
                ->selectRaw('1')
                ->from('album_musician_credit_suppressions as suppressions')
                ->whereColumn('suppressions.album_id', 'imported_credits.album_id')
                ->whereColumn('suppressions.provider', 'imported_credits.provider')
                ->whereColumn('suppressions.source_credit_key', 'imported_credits.source_credit_key'));
        $manual = DB::table('manual_album_musician_credit_track as manual_tracks')
            ->join(
                'manual_album_musician_credits as manual_credits',
                'manual_credits.id',
                '=',
                'manual_tracks.manual_album_musician_credit_id',
            )
            ->join('albums as manual_albums', 'manual_albums.id', '=', 'manual_credits.album_id')
            ->join('tracks as scoped_manual_tracks', 'scoped_manual_tracks.id', '=', 'manual_tracks.track_id')
            ->join('media_files as manual_files', 'manual_files.id', '=', 'scoped_manual_tracks.media_file_id')
            ->select(['manual_credits.musician_id', 'manual_tracks.track_id'])
            ->where('manual_files.status', MediaFileStatus::Available->value)
            ->when($musicianId, fn (QueryBuilder $query, int $id) => $query->where('manual_credits.musician_id', $id));

        if ($scopeLibraryRoots) {
            $this->scopeAlbums($imported, 'imported_albums', $libraryRootId, requireAvailableFiles: false);
            $this->scopeAlbums($manual, 'manual_albums', $libraryRootId, requireAvailableFiles: false);
        }

        return $imported->union($manual);
    }

    /** @param list<int> $albumIds */
    private function effectiveCredits(
        ?int $libraryRootId,
        int $musicianId,
        array $albumIds = [],
    ): QueryBuilder {
        $imported = DB::table('album_musician_credits as imported_credits')
            ->join('albums as imported_albums', 'imported_albums.id', '=', 'imported_credits.album_id')
            ->select([
                'imported_credits.album_id',
                'imported_credits.track_id',
                'imported_credits.role',
                'imported_credits.credited_as',
                'imported_credits.provider',
                'imported_credits.is_guest',
                'imported_credits.is_additional',
            ])
            ->where('imported_credits.musician_id', $musicianId)
            ->when($albumIds !== [], fn (QueryBuilder $query) => $query
                ->whereIn('imported_credits.album_id', $albumIds))
            ->whereNotExists(fn (QueryBuilder $suppressions) => $suppressions
                ->selectRaw('1')
                ->from('album_musician_credit_suppressions as suppressions')
                ->whereColumn('suppressions.album_id', 'imported_credits.album_id')
                ->whereColumn('suppressions.provider', 'imported_credits.provider')
                ->whereColumn('suppressions.source_credit_key', 'imported_credits.source_credit_key'));
        $manual = DB::table('manual_album_musician_credits as manual_credits')
            ->join('albums as manual_albums', 'manual_albums.id', '=', 'manual_credits.album_id')
            ->leftJoin(
                'manual_album_musician_credit_track as manual_tracks',
                'manual_tracks.manual_album_musician_credit_id',
                '=',
                'manual_credits.id',
            )
            ->select([
                'manual_credits.album_id',
                'manual_tracks.track_id',
                'manual_credits.role',
                'manual_credits.credited_as',
            ])
            ->selectRaw("'manual' as provider")
            ->addSelect(['manual_credits.is_guest', 'manual_credits.is_additional'])
            ->where('manual_credits.musician_id', $musicianId)
            ->when($albumIds !== [], fn (QueryBuilder $query) => $query
                ->whereIn('manual_credits.album_id', $albumIds));

        $this->scopeAlbums($imported, 'imported_albums', $libraryRootId);
        $this->scopeAlbums($manual, 'manual_albums', $libraryRootId);

        return $imported->unionAll($manual);
    }

    /** @return list<array{name: string, albumCount: int, trackCount: int}> */
    private function roleSummaries(Collection $credits): array
    {
        return $credits
            ->filter(fn (object $credit): bool => is_string($credit->role) && trim($credit->role) !== '')
            ->groupBy(fn (object $credit): string => mb_strtolower(trim($credit->role)))
            ->map(function (Collection $roleCredits): array {
                return [
                    'name' => trim((string) $roleCredits->first()->role),
                    'albumCount' => $roleCredits->pluck('album_id')->unique()->count(),
                    'trackCount' => $roleCredits->pluck('track_id')->filter()->unique()->count(),
                ];
            })
            ->sort(function (array $left, array $right): int {
                return $right['albumCount'] <=> $left['albumCount']
                    ?: $right['trackCount'] <=> $left['trackCount']
                    ?: strcasecmp($left['name'], $right['name']);
            })
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function uniqueText(Collection $values): array
    {
        return $values
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->sort(fn (string $left, string $right): int => strcasecmp($left, $right))
            ->values()
            ->all();
    }

    private function scopeAlbums(
        QueryBuilder $query,
        string $albumAlias,
        ?int $libraryRootId,
        bool $requireAvailableFiles = true,
    ): void {
        $query
            ->join("library_roots as {$albumAlias}_root", "{$albumAlias}_root.id", '=', "{$albumAlias}.library_root_id")
            ->where("{$albumAlias}_root.enabled", true)
            ->when(
                $libraryRootId,
                fn (QueryBuilder $query, int $id) => $query->where("{$albumAlias}.library_root_id", $id),
            );

        if ($requireAvailableFiles) {
            $query->whereExists(fn (QueryBuilder $files) => $files
                ->selectRaw('1')
                ->from('media_files as available_credit_files')
                ->whereColumn('available_credit_files.album_id', "{$albumAlias}.id")
                ->where('available_credit_files.status', MediaFileStatus::Available->value));
        }
    }
}
