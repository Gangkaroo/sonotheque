<?php

namespace App\Support;

use App\Enums\MediaFileStatus;
use App\Enums\OnlineContentStatus;
use App\Models\Album;
use App\Models\Musician;
use App\Music\Enrichment\AlbumMusicianCreditManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
