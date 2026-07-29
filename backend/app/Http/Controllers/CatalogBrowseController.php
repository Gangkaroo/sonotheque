<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Track;
use App\Models\TrackPlayStatistic;
use App\Support\CatalogPayloads;
use App\Support\LibraryRootScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CatalogBrowseController extends Controller
{
    public function __construct(
        private readonly CatalogPayloads $payloads,
        private readonly LibraryRootScope $libraryRootScope,
    ) {
    }

    public function artists(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:512'],
            'initial' => ['sometimes', 'nullable', 'string', 'in:#,A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z'],
        ]);

        $artists = $this->artistCatalogQuery($libraryRootId)
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'ilike', '%'.$this->escapeLike($search).'%'))
            ->when($filters['initial'] ?? null, fn (Builder $query, string $initial) => $query->where('browse_initial', $initial))
            ->orderByRaw('coalesce(sort_name, name)')
            ->orderBy('name')
            ->paginate(50);

        return response()->json($this->payloads->paginated($artists, fn (Artist $artist) => $this->artistPayload($artist)));
    }

    public function artist(Request $request, Artist $artist): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $artist = $this->artistCatalogQuery($libraryRootId)->findOrFail($artist->id);
        $representativeTrackId = $this->libraryRootScope->tracks(Track::query(), $libraryRootId)
            ->whereHas('album', fn (Builder $query) => $query->where('primary_artist_id', $artist->id))
            ->whereHas('mediaFile')
            ->orderBy('album_id')
            ->orderBy('disc_number')
            ->orderBy('track_number')
            ->orderBy('id')
            ->value('id');

        return response()->json([
            ...$this->artistPayload($artist),
            'representativeTrackId' => $representativeTrackId,
        ]);
    }

    public function albums(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            ...$this->albumFilterRules(),
        ]);

        $albums = $this->filteredAlbumQuery($libraryRootId, $filters)
            ->leftJoin('artists as primary_artists', 'primary_artists.id', '=', 'albums.primary_artist_id')
            ->select([
                'albums.id',
                'albums.title',
                'albums.sort_title',
                'albums.original_release_year',
                'albums.primary_artist_id',
                'albums.artwork_id',
            ])
            ->with(['primaryArtist:id,name', 'artwork:id', 'personalMetadata', 'ownedCopies'])
            ->withCount('tracks');

        $albums = $this->applyAlbumSort($albums, $this->albumSort($filters))
            ->paginate(24);

        return response()->json($this->payloads->paginated($albums, fn (Album $album) => $this->payloads->albumSummary($album)));
    }

    public function album(Request $request, Album $album): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        abort_unless(
            $this->libraryRootScope->albums(Album::query(), $libraryRootId)->whereKey($album->id)->exists(),
            404,
        );

        return response()->json($this->payloads->albumDetail($album));
    }

    public function randomAlbum(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate([
            ...$this->albumFilterRules(),
            'exclude' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $query = $this->filteredAlbumQuery($libraryRootId, $filters);
        if (
            ($filters['exclude'] ?? null)
            && (clone $query)->whereKeyNot($filters['exclude'])->exists()
        ) {
            $query->whereKeyNot($filters['exclude']);
        }

        $album = $query->inRandomOrder()->firstOrFail();

        return response()->json($this->payloads->albumDetail($album));
    }

    public function nextAlbum(Request $request, Album $album): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate($this->albumFilterRules());
        $ids = $this->orderedAlbumIds($libraryRootId, $filters);
        abort_if($ids === [], 404);

        $index = array_search($album->id, $ids, true);
        $nextId = $ids[$index === false ? 0 : ($index + 1) % count($ids)];

        return response()->json($this->payloads->albumDetail(Album::findOrFail($nextId)));
    }

    public function randomTrack(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate([
            ...$this->trackFilterRules(),
            'exclude' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $query = $this->filteredTrackQuery($libraryRootId, $filters);
        if (
            ($filters['exclude'] ?? null)
            && (clone $query)->whereKeyNot($filters['exclude'])->exists()
        ) {
            $query->whereKeyNot($filters['exclude']);
        }

        $track = $query->inRandomOrder()->firstOrFail();

        return response()->json($this->payloads->trackSummary($this->loadPlayableTrack($track)));
    }

    public function nextTrack(Request $request, Track $track): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate($this->trackFilterRules());
        $ids = $this->orderedTrackIds($libraryRootId, $filters);
        abort_if($ids === [], 404);

        $index = array_search($track->id, $ids, true);
        $nextId = $ids[$index === false ? 0 : ($index + 1) % count($ids)];

        return response()->json($this->payloads->trackSummary($this->loadPlayableTrack(Track::findOrFail($nextId))));
    }

    public function tracks(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            ...$this->trackFilterRules(),
        ]);

        $tracks = $this->filteredTrackQuery($libraryRootId, $filters)
            ->leftJoin('albums as sort_albums', 'sort_albums.id', '=', 'tracks.album_id')
            ->leftJoin('artists as sort_artists', 'sort_artists.id', '=', 'sort_albums.primary_artist_id')
            ->leftJoin('track_play_statistics as sort_statistics', 'sort_statistics.track_id', '=', 'tracks.id')
            ->select([
                'tracks.id',
                'tracks.title',
                'tracks.sort_title',
                'tracks.duration_ms',
                'tracks.track_number',
                'tracks.disc_number',
                'tracks.year',
                'tracks.album_id',
            ])
            ->with(['album:id,title,original_release_year,artwork_id', 'album.personalMetadata', 'album.ownedCopies', 'artists:id,name', 'playStatistic:track_id,play_count,first_played_at,last_played_at']);

        $tracks = $this->applyTrackSort($tracks, $filters['sort'] ?? 'album')
            ->paginate(50);

        return response()->json($this->payloads->paginated($tracks, fn (Track $track) => $this->payloads->trackSummary($track)));
    }

    public function track(Request $request, Track $track): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        abort_unless(
            $this->libraryRootScope
                ->tracks(Track::query(), $libraryRootId, availableOnly: false)
                ->whereKey($track->id)
                ->exists(),
            404,
        );

        return response()->json($this->payloads->trackDetail($track));
    }

    public function genres(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $genres = Genre::query()
            ->select(['id', 'name'])
            ->whereHas('tracks', fn (Builder $query) => $this->libraryRootScope->tracks($query, $libraryRootId))
            ->withCount(['tracks' => fn (Builder $query) => $this->libraryRootScope->tracks($query, $libraryRootId)])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'ilike', '%'.$this->escapeLike($search).'%'))
            ->orderBy('name')
            ->paginate(50);

        return response()->json($this->payloads->paginated($genres, fn (Genre $genre) => [
            'id' => $genre->id,
            'name' => $genre->name,
            'trackCount' => $genre->tracks_count,
        ]));
    }

    private function loadPlayableTrack(Track $track): Track
    {
        return $track->load(['album:id,title,original_release_year,artwork_id', 'album.personalMetadata', 'album.ownedCopies', 'artists:id,name', 'playStatistic:track_id,play_count,first_played_at,last_played_at']);
    }

    private function artistCatalogQuery(?int $libraryRootId): Builder
    {
        $statistics = TrackPlayStatistic::query()
            ->join('artist_track', 'artist_track.track_id', '=', 'track_play_statistics.track_id')
            ->join('tracks', 'tracks.id', '=', 'track_play_statistics.track_id')
            ->join('media_files', 'media_files.id', '=', 'tracks.media_file_id')
            ->join('library_roots', 'library_roots.id', '=', 'media_files.library_root_id')
            ->whereColumn('artist_track.artist_id', 'artists.id')
            ->where('library_roots.enabled', true)
            ->when($libraryRootId, fn (Builder $query, int $id) => $query->where('media_files.library_root_id', $id));
        $playCount = (clone $statistics)
            ->selectRaw('coalesce(sum(track_play_statistics.play_count), 0)');
        $playedTrackCount = (clone $statistics)
            ->selectRaw('count(*)')
            ->where('track_play_statistics.play_count', '>', 0);
        $lastPlayedAt = (clone $statistics)
            ->selectRaw('max(track_play_statistics.last_played_at)');

        return Artist::query()
            ->select(['id', 'name', 'sort_name', 'browse_initial'])
            ->where(function (Builder $query) use ($libraryRootId): void {
                $query->whereHas('albums', fn (Builder $albums) => $this->libraryRootScope->albums($albums, $libraryRootId))
                    ->orWhereHas('tracks', fn (Builder $tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId));
            })
            ->addSelect([
                'play_count' => $playCount,
                'played_track_count' => $playedTrackCount,
                'last_played_at' => $lastPlayedAt,
            ])
            ->withCount([
                'albums' => fn (Builder $query) => $this->libraryRootScope->albums($query, $libraryRootId),
                'tracks' => fn (Builder $query) => $this->libraryRootScope->tracks($query, $libraryRootId),
            ]);
    }

    /** @return array<string, mixed> */
    private function artistPayload(Artist $artist): array
    {
        return [
            'id' => $artist->id,
            'name' => $artist->name,
            'browseInitial' => $artist->browse_initial,
            'albumCount' => $artist->albums_count,
            'trackCount' => $artist->tracks_count,
            'playStatistics' => [
                'playCount' => (int) $artist->play_count,
                'playedTrackCount' => (int) $artist->played_track_count,
                'lastPlayedAt' => $artist->last_played_at ? Carbon::parse($artist->last_played_at)->toJSON() : null,
            ],
        ];
    }

    /** @return list<int> */
    private function orderedAlbumIds(?int $libraryRootId, array $filters = []): array
    {
        return $this->applyAlbumSort(
            $this->filteredAlbumQuery($libraryRootId, $filters)
            ->leftJoin('artists as primary_artists', 'primary_artists.id', '=', 'albums.primary_artist_id')
                ->select('albums.id'),
            $this->albumSort($filters),
        )
            ->pluck('albums.id')
            ->all();
    }

    /** @return array<string, list<string>> */
    private function albumFilterRules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:512'],
            'initial' => ['sometimes', 'nullable', 'string', 'in:#,A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'genre' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'artist' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'physicalCopy' => ['sometimes', 'nullable', 'string', 'in:owned,not_owned'],
            'sort' => ['sometimes', 'string', 'in:artist,title,year_asc,year_desc,plays,last_played,added'],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function filteredAlbumQuery(?int $libraryRootId, array $filters): Builder
    {
        return $this->libraryRootScope->albums(Album::query(), $libraryRootId, 'albums.library_root_id')
            ->has('tracks')
            ->when($filters['artist'] ?? null, fn (Builder $query, int $artist) => $query->where('albums.primary_artist_id', $artist))
            ->when($filters['year'] ?? null, fn (Builder $query, int $year) => $query->where('albums.original_release_year', $year))
            ->when($filters['genre'] ?? null, fn (Builder $query, int $genre) => $query->whereHas('tracks.genres', fn (Builder $genreQuery) => $genreQuery->whereKey($genre)))
            ->when(($filters['physicalCopy'] ?? null) === 'owned', fn (Builder $query) => $query
                ->whereHas('ownedCopies', fn (Builder $copy) => $copy->where('is_physical', true)))
            ->when(($filters['physicalCopy'] ?? null) === 'not_owned', fn (Builder $query) => $query
                ->whereDoesntHave('ownedCopies', fn (Builder $copy) => $copy->where('is_physical', true)))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                foreach ($this->searchTerms($search) as $term) {
                    $pattern = '%'.$this->escapeLike($term).'%';
                    $query->where(function (Builder $query) use ($pattern): void {
                        $query->where('albums.title', 'ilike', $pattern)
                            ->orWhereHas('primaryArtist', fn (Builder $artistQuery) => $artistQuery->where('name', 'ilike', $pattern));
                    });
                }
            })
            ->when(
                $filters['initial'] ?? null,
                fn (Builder $query, string $initial) => $query->whereHas(
                    'primaryArtist',
                    fn (Builder $artistQuery) => $artistQuery->where('browse_initial', $initial),
                ),
            );
    }

    /** @param array<string, mixed> $filters */
    private function albumSort(array $filters): string
    {
        return $filters['sort'] ?? (($filters['artist'] ?? null) ? 'year_asc' : 'artist');
    }

    /** @return list<int> */
    private function orderedTrackIds(?int $libraryRootId, array $filters = []): array
    {
        return $this->applyTrackSort(
            $this->filteredTrackQuery($libraryRootId, $filters)
                ->leftJoin('albums as sort_albums', 'sort_albums.id', '=', 'tracks.album_id')
                ->leftJoin('artists as sort_artists', 'sort_artists.id', '=', 'sort_albums.primary_artist_id')
                ->leftJoin('track_play_statistics as sort_statistics', 'sort_statistics.track_id', '=', 'tracks.id')
                ->select('tracks.id'),
            $filters['sort'] ?? 'album',
        )
            ->pluck('tracks.id')
            ->all();
    }

    /** @return array<string, list<string>> */
    private function trackFilterRules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:512'],
            'genre' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'artist' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'playStatus' => ['sometimes', 'nullable', 'string', 'in:never'],
            'physicalCopy' => ['sometimes', 'nullable', 'string', 'in:owned,not_owned'],
            'sort' => ['sometimes', 'string', 'in:album,title,year_asc,year_desc,plays,last_played,added'],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function filteredTrackQuery(?int $libraryRootId, array $filters): Builder
    {
        return $this->libraryRootScope->tracks(Track::query(), $libraryRootId)
            ->when($filters['artist'] ?? null, fn (Builder $query, int $artist) => $query->whereHas('artists', fn (Builder $artistQuery) => $artistQuery->whereKey($artist)))
            ->when($filters['genre'] ?? null, fn (Builder $query, int $genre) => $query->whereHas('genres', fn (Builder $genreQuery) => $genreQuery->whereKey($genre)))
            ->when(($filters['physicalCopy'] ?? null) === 'owned', fn (Builder $query) => $query
                ->whereHas('album.ownedCopies', fn (Builder $copy) => $copy->where('is_physical', true)))
            ->when(($filters['physicalCopy'] ?? null) === 'not_owned', fn (Builder $query) => $query
                ->whereHas('album', fn (Builder $album) => $album
                    ->whereDoesntHave('ownedCopies', fn (Builder $copy) => $copy->where('is_physical', true))))
            ->when(($filters['playStatus'] ?? null) === 'never', function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereDoesntHave('playStatistic')
                        ->orWhereHas('playStatistic', fn (Builder $statisticsQuery) => $statisticsQuery->where('play_count', 0));
                });
            })
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                foreach ($this->searchTerms($search) as $term) {
                    $query->whereIn('tracks.id', function ($matches) use ($term): void {
                        $matches
                            ->select('searchable_tracks.id')
                            ->from('tracks as searchable_tracks')
                            ->whereRaw(
                                "to_tsvector('simple', coalesce(searchable_tracks.title, '')) @@ to_tsquery('simple', quote_literal(?) || ':*')",
                                [$term],
                            )
                            ->unionAll(
                                DB::table('artist_track as searchable_artist_tracks')
                                    ->select('searchable_artist_tracks.track_id')
                                    ->join('artists as searchable_artists', 'searchable_artists.id', '=', 'searchable_artist_tracks.artist_id')
                                    ->whereRaw(
                                        "to_tsvector('simple', coalesce(searchable_artists.name, '')) @@ to_tsquery('simple', quote_literal(?) || ':*')",
                                        [$term],
                                    ),
                            );
                    });
                }
            });
    }

    private function applyAlbumSort(Builder $query, string $sort): Builder
    {
        if (in_array($sort, ['plays', 'last_played'], true)) {
            $statistics = DB::table('track_play_statistics as statistics')
                ->join('tracks as statistics_tracks', 'statistics_tracks.id', '=', 'statistics.track_id')
                ->selectRaw(
                    'statistics_tracks.album_id, sum(statistics.play_count) as play_count, '
                    .'max(statistics.last_played_at) as last_played_at',
                )
                ->groupBy('statistics_tracks.album_id');
            $query->leftJoinSub(
                $statistics,
                'sort_statistics',
                fn ($join) => $join->on('sort_statistics.album_id', '=', 'albums.id'),
            );
        }

        match ($sort) {
            'title' => $query
                ->orderByRaw('coalesce(albums.sort_title, albums.title)')
                ->orderBy('albums.title'),
            'year_asc' => $this->orderAlbumsByYear($query, 'asc'),
            'year_desc' => $this->orderAlbumsByYear($query, 'desc'),
            'plays' => $query
                ->orderByRaw('coalesce(sort_statistics.play_count, 0) desc')
                ->orderByRaw('coalesce(albums.sort_title, albums.title)')
                ->orderBy('albums.title'),
            'last_played' => $query
                ->orderByRaw('sort_statistics.last_played_at desc nulls last')
                ->orderByRaw('coalesce(albums.sort_title, albums.title)')
                ->orderBy('albums.title'),
            'added' => $query
                ->orderByDesc('albums.created_at'),
            default => $this->orderAlbumsByArtist($query),
        };

        return $query->orderBy('albums.id');
    }

    private function applyTrackSort(Builder $query, string $sort): Builder
    {
        match ($sort) {
            'title' => $query
                ->orderByRaw('coalesce(tracks.sort_title, tracks.title)')
                ->orderBy('tracks.title'),
            'year_asc' => $this->orderTracksByYear($query, 'asc'),
            'year_desc' => $this->orderTracksByYear($query, 'desc'),
            'plays' => $query
                ->orderByRaw('coalesce(sort_statistics.play_count, 0) desc')
                ->orderByRaw('coalesce(tracks.sort_title, tracks.title)')
                ->orderBy('tracks.title'),
            'last_played' => $query
                ->orderByRaw('sort_statistics.last_played_at desc nulls last')
                ->orderByRaw('coalesce(tracks.sort_title, tracks.title)')
                ->orderBy('tracks.title'),
            'added' => $query
                ->orderByDesc('tracks.created_at'),
            default => $this->orderTracksByAlbum($query),
        };

        return $query->orderBy('tracks.id');
    }

    private function orderAlbumsByArtist(Builder $query): Builder
    {
        return $query
            ->orderByRaw('primary_artists.name is null')
            ->orderByRaw('coalesce(primary_artists.sort_name, primary_artists.name)')
            ->orderBy('primary_artists.name')
            ->orderByRaw('coalesce(albums.sort_title, albums.title)')
            ->orderBy('albums.title');
    }

    private function orderAlbumsByYear(Builder $query, string $direction): Builder
    {
        return $query
            ->orderByRaw('albums.original_release_year is null')
            ->orderBy('albums.original_release_year', $direction)
            ->orderByRaw('coalesce(albums.sort_title, albums.title)')
            ->orderBy('albums.title');
    }

    private function orderTracksByAlbum(Builder $query): Builder
    {
        return $query
            ->orderByRaw('sort_artists.name is null')
            ->orderByRaw('coalesce(sort_artists.sort_name, sort_artists.name)')
            ->orderBy('sort_artists.name')
            ->orderByRaw('coalesce(sort_albums.sort_title, sort_albums.title)')
            ->orderBy('sort_albums.title')
            ->orderBy('tracks.disc_number')
            ->orderBy('tracks.track_number');
    }

    private function orderTracksByYear(Builder $query, string $direction): Builder
    {
        return $query
            ->orderByRaw('sort_albums.original_release_year is null')
            ->orderBy('sort_albums.original_release_year', $direction)
            ->orderByRaw('coalesce(sort_albums.sort_title, sort_albums.title)')
            ->orderBy('sort_albums.title')
            ->orderBy('tracks.disc_number')
            ->orderBy('tracks.track_number');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value));
    }

    /** @return list<string> */
    private function searchTerms(string $search): array
    {
        return array_slice(
            array_values(array_filter(
                preg_split('/\s+/u', trim($search)) ?: [],
                fn (string $term): bool => $term !== '',
            )),
            0,
            12,
        );
    }
}
