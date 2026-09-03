<?php

namespace App\Http\Controllers;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Track;
use App\Models\TrackPlayStatistic;
use App\Support\CatalogPayloads;
use App\Support\LibraryRootScope;
use App\Support\MusicianCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CatalogBrowseController extends Controller
{
    public function __construct(
        private readonly CatalogPayloads $payloads,
        private readonly LibraryRootScope $libraryRootScope,
        private readonly MusicianCatalog $musicians,
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
            ->orderBy('name');
        $artists = $artists->paginate(
            perPage: 50,
            total: fn (): int => $this->artistCatalogTotal($libraryRootId, $filters),
        );
        $this->hydrateArtistAlbumCounts($artists->getCollection(), $libraryRootId);

        return response()->json($this->payloads->paginated($artists, fn (Artist $artist) => $this->artistPayload($artist)));
    }

    public function artist(Request $request, Artist $artist): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $artist = $this->artistCatalogQuery($libraryRootId)->findOrFail($artist->id);
        $this->hydrateArtistAlbumCounts([$artist], $libraryRootId);
        $representativeTrackId = $this->libraryRootScope->tracks(Track::query(), $libraryRootId)
            ->where(function (Builder $query) use ($artist): void {
                $query->whereHas('artists', fn (Builder $artists) => $artists->whereKey($artist->id))
                    ->orWhereHas('album', fn (Builder $album) => $album->where('primary_artist_id', $artist->id));
            })
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

    public function artistTrackNavigation(Request $request, Artist $artist, Track $track): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $ids = $this->orderedTrackIds($libraryRootId, ['artist' => $artist->id]);
        $index = array_search($track->id, $ids, true);
        abort_if($index === false, 404);

        return response()->json([
            'previousTrackId' => $index > 0 ? $ids[$index - 1] : null,
            'nextTrackId' => $index < count($ids) - 1 ? $ids[$index + 1] : null,
        ]);
    }

    public function artistTracks(Request $request, Artist $artist): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate([
            'confirmationThreshold' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ]);
        $filteredTracks = $this->filteredTrackQuery($libraryRootId, ['artist' => $artist->id]);
        $total = (clone $filteredTracks)->count();

        if (
            isset($filters['confirmationThreshold'])
            && $total >= (int) $filters['confirmationThreshold']
        ) {
            return response()->json([
                'total' => $total,
                'requiresConfirmation' => true,
                'tracks' => [],
            ]);
        }

        $tracks = $this->applyTrackSort($this->trackBrowseQuery($filteredTracks), 'album')->get();

        return response()->json([
            'total' => $tracks->count(),
            'requiresConfirmation' => false,
            'tracks' => $tracks
                ->map(fn (Track $track) => $this->payloads->trackSummary($track))
                ->values(),
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
                'albums.rating_half_steps',
                'albums.primary_artist_id',
                'albums.artwork_id',
            ])
            ->with(['primaryArtist:id,name', 'artwork:id', 'personalMetadata', 'ownedCopies'])
            ->withCount('tracks');

        $albums = $this->applyAlbumSort($albums, $this->albumSort($filters))
            ->paginate(
                perPage: 24,
                total: $this->albumPaginationTotal($libraryRootId, $filters),
            );

        $musicianId = isset($filters['musician']) ? (int) $filters['musician'] : null;
        $creditSummaries = $musicianId === null
            ? []
            : $this->musicians->albumCreditSummaries(
                $musicianId,
                collect($albums->items())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                $libraryRootId,
            );

        return response()->json($this->payloads->paginated($albums, function (Album $album) use ($creditSummaries, $musicianId): array {
            return [
                ...$this->payloads->albumSummary($album),
                ...($musicianId === null ? [] : [
                    'musicianCredits' => $creditSummaries[$album->id] ?? null,
                ]),
            ];
        }));
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
        $album = $this->randomCatalogItem($query, $filters['exclude'] ?? null);

        return response()->json($this->payloads->albumPlayback($album));
    }

    public function nextAlbum(Request $request, Album $album): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate($this->albumFilterRules());
        $ids = $this->orderedAlbumIds($libraryRootId, $filters);
        abort_if($ids === [], 404);

        $index = array_search($album->id, $ids, true);
        $nextId = $ids[$index === false ? 0 : ($index + 1) % count($ids)];

        return response()->json($this->payloads->albumPlayback(Album::findOrFail($nextId)));
    }

    public function randomTrack(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate([
            ...$this->trackFilterRules(),
            'exclude' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $query = $this->filteredTrackQuery($libraryRootId, $filters);
        $track = $this->randomCatalogItem($query, $filters['exclude'] ?? null);

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

        $filteredTracks = $this->filteredTrackQuery($libraryRootId, $filters);
        $tracks = $this->trackBrowseQuery($filteredTracks);

        $tracks = $this->applyTrackSort($tracks, $filters['sort'] ?? 'album')
            ->paginate(
                perPage: 50,
                total: fn (): int => $this->trackCatalogTotal($libraryRootId, $filters, $filteredTracks),
            );

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
            ->whereHas(
                'tracks',
                fn (Builder $tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId),
            )
            ->withCount([
                'tracks' => fn (Builder $tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId),
            ])
            ->when(
                $filters['search'] ?? null,
                fn (Builder $query, string $search) => $query->where(
                    'genres.name',
                    'ilike',
                    '%'.$this->escapeLike($search).'%',
                ),
            )
            ->orderBy('genres.name');
        $genres = $genres->paginate(50);

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

        return $this->artistCatalogBaseQuery($libraryRootId)
            ->select(['id', 'name', 'sort_name', 'browse_initial', 'created_at', 'updated_at'])
            ->addSelect([
                'play_count' => $playCount,
                'played_track_count' => $playedTrackCount,
                'last_played_at' => $lastPlayedAt,
            ])
            ->withCount([
                'tracks' => fn (Builder $query) => $this->libraryRootScope->tracks($query, $libraryRootId),
            ]);
    }

    /** @param iterable<int, Artist> $artists */
    private function hydrateArtistAlbumCounts(iterable $artists, ?int $libraryRootId): void
    {
        $artists = collect($artists);
        $artistIds = $artists->pluck('id')->all();
        if ($artistIds === []) {
            return;
        }

        $primaryArtistAlbums = DB::table('albums as artist_primary_albums')
            ->join('media_files as artist_primary_album_files', 'artist_primary_album_files.album_id', '=', 'artist_primary_albums.id')
            ->join('library_roots as artist_primary_album_roots', 'artist_primary_album_roots.id', '=', 'artist_primary_albums.library_root_id')
            ->whereIn('artist_primary_albums.primary_artist_id', $artistIds)
            ->where('artist_primary_album_files.status', MediaFileStatus::Available->value)
            ->where('artist_primary_album_roots.enabled', true)
            ->when($libraryRootId, fn ($query, int $id) => $query->where('artist_primary_albums.library_root_id', $id))
            ->selectRaw('artist_primary_albums.primary_artist_id as artist_id, artist_primary_albums.id as album_id')
            ->distinct();
        $trackArtistAlbums = DB::table('artist_track as artist_album_links')
            ->join('tracks as artist_album_tracks', 'artist_album_tracks.id', '=', 'artist_album_links.track_id')
            ->join('media_files as artist_album_track_files', 'artist_album_track_files.id', '=', 'artist_album_tracks.media_file_id')
            ->join('library_roots as artist_album_track_roots', 'artist_album_track_roots.id', '=', 'artist_album_track_files.library_root_id')
            ->whereIn('artist_album_links.artist_id', $artistIds)
            ->where('artist_album_track_files.status', MediaFileStatus::Available->value)
            ->where('artist_album_track_roots.enabled', true)
            ->when($libraryRootId, fn ($query, int $id) => $query->where('artist_album_track_files.library_root_id', $id))
            ->selectRaw('artist_album_links.artist_id as artist_id, artist_album_tracks.album_id as album_id')
            ->distinct();
        $counts = DB::query()
            ->fromSub($primaryArtistAlbums->union($trackArtistAlbums), 'artist_album_catalog')
            ->selectRaw('artist_id, count(*) as album_count')
            ->groupBy('artist_id')
            ->pluck('album_count', 'artist_id');

        foreach ($artists as $artist) {
            $artist->setAttribute('albums_count', (int) ($counts[$artist->id] ?? 0));
        }
    }

    /** @param array<string, mixed> $filters */
    private function artistCatalogTotal(?int $libraryRootId, array $filters): int
    {
        if (blank($filters['search'] ?? null) && blank($filters['initial'] ?? null)) {
            return DB::query()
                ->fromSub($this->artistCatalogIds($libraryRootId), 'catalog_artist_ids')
                ->count();
        }

        return $this->artistCatalogBaseQuery($libraryRootId)
            ->when(
                $filters['search'] ?? null,
                fn (Builder $query, string $search) => $query->where(
                    'artists.name',
                    'ilike',
                    '%'.$this->escapeLike($search).'%',
                ),
            )
            ->when(
                $filters['initial'] ?? null,
                fn (Builder $query, string $initial) => $query->where('artists.browse_initial', $initial),
            )
            ->count('artists.id');
    }

    private function artistCatalogIds(?int $libraryRootId): QueryBuilder
    {
        $albumArtists = DB::table('albums as total_albums')
            ->join('media_files as total_album_files', 'total_album_files.album_id', '=', 'total_albums.id')
            ->join('library_roots as total_album_roots', 'total_album_roots.id', '=', 'total_albums.library_root_id')
            ->whereNotNull('total_albums.primary_artist_id')
            ->where('total_album_files.status', MediaFileStatus::Available->value)
            ->where('total_album_roots.enabled', true)
            ->when(
                $libraryRootId,
                fn (QueryBuilder $query, int $id) => $query->where('total_albums.library_root_id', $id),
            )
            ->selectRaw('total_albums.primary_artist_id as artist_id');
        $trackArtists = DB::table('artist_track as total_artist_tracks')
            ->join('tracks as total_tracks', 'total_tracks.id', '=', 'total_artist_tracks.track_id')
            ->join('media_files as total_track_files', 'total_track_files.id', '=', 'total_tracks.media_file_id')
            ->join('library_roots as total_track_roots', 'total_track_roots.id', '=', 'total_track_files.library_root_id')
            ->where('total_track_files.status', MediaFileStatus::Available->value)
            ->where('total_track_roots.enabled', true)
            ->when(
                $libraryRootId,
                fn (QueryBuilder $query, int $id) => $query->where('total_track_files.library_root_id', $id),
            )
            ->select('total_artist_tracks.artist_id');

        return $albumArtists->union($trackArtists);
    }

    private function artistCatalogBaseQuery(?int $libraryRootId): Builder
    {
        return Artist::query()
            ->where(function (Builder $query) use ($libraryRootId): void {
                $query->whereHas(
                    'albums',
                    fn (Builder $albums) => $this->libraryRootScope->albums($albums, $libraryRootId),
                )->orWhereHas(
                    'tracks',
                    fn (Builder $tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId),
                );
            });
    }

    /** @return array<string, mixed> */
    private function artistPayload(Artist $artist): array
    {
        return [
            'id' => $artist->id,
            'name' => $artist->name,
            'browseInitial' => $artist->browse_initial,
            'createdAt' => $artist->created_at?->toJSON(),
            'updatedAt' => $artist->updated_at?->toJSON(),
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
            'label' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'artist' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'musician' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'physicalCopy' => ['sometimes', 'nullable', 'string', 'in:owned,not_owned'],
            'sort' => ['sometimes', 'string', 'in:artist,title,year_asc,year_desc,plays,last_played,added'],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function filteredAlbumQuery(?int $libraryRootId, array $filters): Builder
    {
        return $this->libraryRootScope->albums(Album::query(), $libraryRootId, 'albums.library_root_id')
            ->has('tracks')
            ->when(
                $filters['artist'] ?? null,
                fn (Builder $query, int $artist) => $query->whereIn(
                    'albums.id',
                    $this->artistAlbumCatalogIds($artist, $libraryRootId),
                ),
            )
            ->when($filters['musician'] ?? null, fn (Builder $query, int $musician) => $query
                ->whereIn('albums.id', $this->musicians->albumIdsForMusician($musician)))
            ->when($filters['year'] ?? null, fn (Builder $query, int $year) => $query->where('albums.original_release_year', $year))
            ->when($filters['genre'] ?? null, fn (Builder $query, int $genre) => $query->whereHas('tracks.genres', fn (Builder $genreQuery) => $genreQuery->whereKey($genre)))
            ->when($filters['label'] ?? null, fn (Builder $query, int $label) => $query
                ->whereHas('recordLabelAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                    ->where('record_label_id', $label)))
            ->when(($filters['physicalCopy'] ?? null) === 'owned', fn (Builder $query) => $query
                ->whereHas('ownedCopies', fn (Builder $copy) => $copy->where('is_physical', true)))
            ->when(($filters['physicalCopy'] ?? null) === 'not_owned', fn (Builder $query) => $query
                ->whereDoesntHave('ownedCopies', fn (Builder $copy) => $copy->where('is_physical', true)))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                foreach ($this->searchTerms($search) as $term) {
                    $query->whereIn('albums.id', $this->albumSearchIds($term));
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

    /**
     * @template TModel of Model
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    private function randomCatalogItem(Builder $query, ?int $excludeId): Model
    {
        $candidateQuery = clone $query;
        if ($excludeId !== null) {
            $candidateQuery->whereKeyNot($excludeId);
        }

        return $candidateQuery->inRandomOrder()->first()
            ?? $query->inRandomOrder()->firstOrFail();
    }

    private function albumSearchIds(string $term): QueryBuilder
    {
        $pattern = '%'.$this->escapeLike($term).'%';
        $titleMatches = DB::table('albums as searchable_albums')
            ->select('searchable_albums.id')
            ->where('searchable_albums.title', 'ilike', $pattern);
        $artistMatches = DB::table('albums as searchable_artist_albums')
            ->join(
                'artists as searchable_album_artists',
                'searchable_album_artists.id',
                '=',
                'searchable_artist_albums.primary_artist_id',
            )
            ->select('searchable_artist_albums.id')
            ->where('searchable_album_artists.name', 'ilike', $pattern);

        return $titleMatches->unionAll($artistMatches);
    }

    private function albumCatalogTotal(?int $libraryRootId): int
    {
        return DB::table('media_files as total_album_files')
            ->join('albums as total_albums', 'total_albums.id', '=', 'total_album_files.album_id')
            ->join('library_roots as total_album_roots', 'total_album_roots.id', '=', 'total_albums.library_root_id')
            ->where('total_album_files.status', MediaFileStatus::Available->value)
            ->where('total_album_roots.enabled', true)
            ->when(
                $libraryRootId,
                fn ($query, int $id) => $query->where('total_albums.library_root_id', $id),
            )
            ->whereExists(
                fn ($query) => $query
                    ->selectRaw('1')
                    ->from('tracks as total_album_tracks')
                    ->whereColumn('total_album_tracks.album_id', 'total_albums.id'),
            )
            ->distinct()
            ->count('total_albums.id');
    }

    /** @param array<string, mixed> $filters */
    private function albumPaginationTotal(?int $libraryRootId, array $filters): ?callable
    {
        if ($this->hasOnlyFilter($filters, 'artist', [
            'search',
            'initial',
            'year',
            'genre',
            'label',
            'artist',
            'musician',
            'physicalCopy',
        ])) {
            return fn (): int => $this->artistAlbumCatalogTotal((int) $filters['artist'], $libraryRootId);
        }

        return $this->hasAlbumFilters($filters)
            ? null
            : fn (): int => $this->albumCatalogTotal($libraryRootId);
    }

    private function artistAlbumCatalogTotal(int $artistId, ?int $libraryRootId): int
    {
        return DB::query()
            ->fromSub($this->artistAlbumCatalogIds($artistId, $libraryRootId), 'filtered_artist_albums')
            ->count('album_id');
    }

    private function artistAlbumCatalogIds(int $artistId, ?int $libraryRootId): QueryBuilder
    {
        $primaryArtistAlbums = DB::table('albums as filtered_primary_albums')
            ->join('media_files as filtered_primary_files', 'filtered_primary_files.album_id', '=', 'filtered_primary_albums.id')
            ->join('library_roots as filtered_primary_roots', 'filtered_primary_roots.id', '=', 'filtered_primary_albums.library_root_id')
            ->where('filtered_primary_albums.primary_artist_id', $artistId)
            ->where('filtered_primary_files.status', MediaFileStatus::Available->value)
            ->where('filtered_primary_roots.enabled', true)
            ->when($libraryRootId, fn ($query, int $id) => $query->where('filtered_primary_albums.library_root_id', $id))
            ->selectRaw('filtered_primary_albums.id as album_id');
        $trackArtistAlbums = DB::table('artist_track as filtered_artist_tracks')
            ->join('tracks as filtered_tracks', 'filtered_tracks.id', '=', 'filtered_artist_tracks.track_id')
            ->join('media_files as filtered_track_files', 'filtered_track_files.id', '=', 'filtered_tracks.media_file_id')
            ->join('library_roots as filtered_track_roots', 'filtered_track_roots.id', '=', 'filtered_track_files.library_root_id')
            ->where('filtered_artist_tracks.artist_id', $artistId)
            ->where('filtered_track_files.status', MediaFileStatus::Available->value)
            ->where('filtered_track_roots.enabled', true)
            ->when($libraryRootId, fn ($query, int $id) => $query->where('filtered_track_files.library_root_id', $id))
            ->selectRaw('filtered_tracks.album_id as album_id');

        return $primaryArtistAlbums->union($trackArtistAlbums);
    }

    /** @param array<string, mixed> $filters */
    private function hasAlbumFilters(array $filters): bool
    {
        foreach (['search', 'initial', 'year', 'genre', 'label', 'artist', 'musician', 'physicalCopy'] as $filter) {
            if (($filters[$filter] ?? null) !== null && ($filters[$filter] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $catalogFilters
     */
    private function hasOnlyFilter(array $filters, string $expectedFilter, array $catalogFilters): bool
    {
        if (($filters[$expectedFilter] ?? null) === null || ($filters[$expectedFilter] ?? '') === '') {
            return false;
        }

        foreach ($catalogFilters as $filter) {
            if ($filter === $expectedFilter) {
                continue;
            }

            if (($filters[$filter] ?? null) !== null && ($filters[$filter] ?? '') !== '') {
                return false;
            }
        }

        return true;
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
            'musician' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'playStatus' => ['sometimes', 'nullable', 'string', 'in:never'],
            'physicalCopy' => ['sometimes', 'nullable', 'string', 'in:owned,not_owned'],
            'sort' => ['sometimes', 'string', 'in:album,title,year_asc,year_desc,plays,last_played,added'],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function filteredTrackQuery(?int $libraryRootId, array $filters): Builder
    {
        return $this->libraryRootScope->tracks(Track::query(), $libraryRootId)
            ->when(
                $filters['artist'] ?? null,
                fn (Builder $query, int $artist) => $query->whereIn(
                    'tracks.id',
                    DB::table('artist_track')->select('track_id')->where('artist_id', $artist),
                ),
            )
            ->when($filters['musician'] ?? null, fn (Builder $query, int $musician) => $query
                ->whereIn('tracks.id', $this->musicians->trackIdsForMusician($musician)))
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

    private function trackBrowseQuery(Builder $filteredTracks): Builder
    {
        return (clone $filteredTracks)
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
                'tracks.rating_half_steps',
                'tracks.album_id',
            ])
            ->with([
                'album:id,title,original_release_year,artwork_id',
                'album.personalMetadata',
                'album.ownedCopies',
                'artists:id,name',
                'playStatistic:track_id,play_count,first_played_at,last_played_at',
            ]);
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

    /** @param array<string, mixed> $filters */
    private function trackCatalogTotal(?int $libraryRootId, array $filters, Builder $filteredTracks): int
    {
        if (! $this->hasOnlyFilter($filters, 'artist', [
            'search',
            'genre',
            'artist',
            'musician',
            'playStatus',
            'physicalCopy',
        ])) {
            return (clone $filteredTracks)->count();
        }

        return DB::table('artist_track as filtered_artist_tracks')
            ->join('tracks as filtered_tracks', 'filtered_tracks.id', '=', 'filtered_artist_tracks.track_id')
            ->join('media_files as filtered_track_files', 'filtered_track_files.id', '=', 'filtered_tracks.media_file_id')
            ->join('library_roots as filtered_track_roots', 'filtered_track_roots.id', '=', 'filtered_track_files.library_root_id')
            ->where('filtered_artist_tracks.artist_id', (int) $filters['artist'])
            ->where('filtered_track_files.status', MediaFileStatus::Available->value)
            ->where('filtered_track_roots.enabled', true)
            ->when($libraryRootId, fn ($query, int $id) => $query->where('filtered_track_files.library_root_id', $id))
            ->count('filtered_tracks.id');
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
