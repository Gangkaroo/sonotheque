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
            'search' => ['sometimes', 'nullable', 'string', 'max:512'],
            'initial' => ['sometimes', 'nullable', 'string', 'in:#,A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'genre' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'artist' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $albums = $this->libraryRootScope->albums(Album::query(), $libraryRootId, 'albums.library_root_id')
            ->leftJoin('artists as primary_artists', 'primary_artists.id', '=', 'albums.primary_artist_id')
            ->select([
                'albums.id',
                'albums.title',
                'albums.sort_title',
                'albums.original_release_year',
                'albums.primary_artist_id',
                'albums.artwork_id',
            ])
            ->with(['primaryArtist:id,name', 'artwork:id'])
            ->withCount('tracks')
            ->has('tracks')
            ->when($filters['artist'] ?? null, fn (Builder $query, int $artist) => $query->where('albums.primary_artist_id', $artist))
            ->when($filters['year'] ?? null, fn (Builder $query, int $year) => $query->where('albums.original_release_year', $year))
            ->when($filters['genre'] ?? null, fn (Builder $query, int $genre) => $query->whereHas('tracks.genres', fn (Builder $genreQuery) => $genreQuery->whereKey($genre)))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $pattern = '%'.$this->escapeLike($search).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->where('albums.title', 'ilike', $pattern)
                        ->orWhereHas('primaryArtist', fn (Builder $artistQuery) => $artistQuery->where('name', 'ilike', $pattern));
                });
            })
            ->when($filters['initial'] ?? null, fn (Builder $query, string $initial) => $query->where('primary_artists.browse_initial', $initial))
            ->orderByRaw('primary_artists.name is null')
            ->orderByRaw('coalesce(primary_artists.sort_name, primary_artists.name)')
            ->orderBy('primary_artists.name')
            ->orderByRaw('coalesce(albums.sort_title, albums.title)')
            ->orderBy('albums.title')
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
            'exclude' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $query = $this->libraryRootScope->albums(Album::query(), $libraryRootId)->has('tracks');
        if ((clone $query)->count() > 1 && ($filters['exclude'] ?? null)) {
            $query->whereKeyNot($filters['exclude']);
        }

        $album = $query->inRandomOrder()->firstOrFail();

        return response()->json($this->payloads->albumDetail($album));
    }

    public function nextAlbum(Request $request, Album $album): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $ids = $this->orderedAlbumIds($libraryRootId);
        abort_if($ids === [], 404);

        $index = array_search($album->id, $ids, true);
        $nextId = $ids[$index === false ? 0 : ($index + 1) % count($ids)];

        return response()->json($this->payloads->albumDetail(Album::findOrFail($nextId)));
    }

    public function randomTrack(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate([
            'exclude' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $query = $this->libraryRootScope->tracks(Track::query(), $libraryRootId);
        if ((clone $query)->count() > 1 && ($filters['exclude'] ?? null)) {
            $query->whereKeyNot($filters['exclude']);
        }

        $track = $query->inRandomOrder()->firstOrFail();

        return response()->json($this->payloads->trackSummary($this->loadPlayableTrack($track)));
    }

    public function nextTrack(Request $request, Track $track): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $ids = $this->orderedTrackIds($libraryRootId);
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
            'search' => ['sometimes', 'nullable', 'string', 'max:512'],
            'genre' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'artist' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'playStatus' => ['sometimes', 'nullable', 'string', 'in:never'],
        ]);

        $tracks = $this->libraryRootScope->tracks(Track::query(), $libraryRootId)
            ->select(['id', 'title', 'sort_title', 'duration_ms', 'track_number', 'disc_number', 'album_id'])
            ->with(['album:id,title,original_release_year,artwork_id', 'artists:id,name', 'playStatistic:track_id,play_count,first_played_at,last_played_at'])
            ->when($filters['artist'] ?? null, fn (Builder $query, int $artist) => $query->whereHas('artists', fn (Builder $artistQuery) => $artistQuery->whereKey($artist)))
            ->when($filters['genre'] ?? null, fn (Builder $query, int $genre) => $query->whereHas('genres', fn (Builder $genreQuery) => $genreQuery->whereKey($genre)))
            ->when(($filters['playStatus'] ?? null) === 'never', function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereDoesntHave('playStatistic')
                        ->orWhereHas('playStatistic', fn (Builder $statisticsQuery) => $statisticsQuery->where('play_count', 0));
                });
            })
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $pattern = '%'.$this->escapeLike($search).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->where('title', 'ilike', $pattern)
                        ->orWhereHas('artists', fn (Builder $artistQuery) => $artistQuery->where('name', 'ilike', $pattern));
                });
            })
            ->orderBy('album_id')
            ->orderBy('disc_number')
            ->orderBy('track_number')
            ->orderBy('id')
            ->paginate(50);

        return response()->json($this->payloads->paginated($tracks, fn (Track $track) => $this->payloads->trackSummary($track)));
    }

    public function track(Request $request, Track $track): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        abort_unless(
            $this->libraryRootScope->tracks(Track::query(), $libraryRootId)->whereKey($track->id)->exists(),
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
        return $track->load(['album:id,title,original_release_year,artwork_id', 'artists:id,name', 'playStatistic:track_id,play_count,first_played_at,last_played_at']);
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
    private function orderedAlbumIds(?int $libraryRootId): array
    {
        return $this->libraryRootScope->albums(Album::query(), $libraryRootId, 'albums.library_root_id')
            ->leftJoin('artists as primary_artists', 'primary_artists.id', '=', 'albums.primary_artist_id')
            ->has('tracks')
            ->orderByRaw('primary_artists.name is null')
            ->orderByRaw('coalesce(primary_artists.sort_name, primary_artists.name)')
            ->orderBy('primary_artists.name')
            ->orderByRaw('coalesce(albums.sort_title, albums.title)')
            ->orderBy('albums.title')
            ->pluck('albums.id')
            ->all();
    }

    /** @return list<int> */
    private function orderedTrackIds(?int $libraryRootId): array
    {
        return $this->libraryRootScope->tracks(Track::query(), $libraryRootId)
            ->join('albums', 'albums.id', '=', 'tracks.album_id')
            ->leftJoin('artists as primary_artists', 'primary_artists.id', '=', 'albums.primary_artist_id')
            ->orderByRaw('primary_artists.name is null')
            ->orderByRaw('coalesce(primary_artists.sort_name, primary_artists.name)')
            ->orderBy('primary_artists.name')
            ->orderByRaw('coalesce(albums.sort_title, albums.title)')
            ->orderBy('albums.title')
            ->orderBy('tracks.disc_number')
            ->orderBy('tracks.track_number')
            ->orderBy('tracks.id')
            ->pluck('tracks.id')
            ->all();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value));
    }
}
