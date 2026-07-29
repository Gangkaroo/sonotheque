<?php

namespace App\Http\Controllers;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Track;
use App\Music\Playlists\PlaylistFileSynchronizationDispatcher;
use App\Support\LibraryRootScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrashController extends Controller
{
    public function __construct(
        private readonly LibraryRootScope $libraryRootScope,
        private readonly PlaylistFileSynchronizationDispatcher $playlistSynchronizationDispatcher,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $tracks = Track::query()
            ->join('media_files', 'media_files.id', '=', 'tracks.media_file_id')
            ->join('library_roots', 'library_roots.id', '=', 'media_files.library_root_id')
            ->where('media_files.status', MediaFileStatus::Missing->value)
            ->where('library_roots.enabled', true)
            ->when(
                $libraryRootId,
                fn (Builder $query, int $id) => $query->where('media_files.library_root_id', $id),
            )
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search))
            ->select('tracks.*')
            ->with([
                'album:id,title',
                'artists:id,name',
                'mediaFile:id,library_root_id,relative_path,status,updated_at',
                'mediaFile.libraryRoot:id,name',
                'playStatistic:track_id,play_count',
            ])
            ->withCount('playlistItems')
            ->orderByDesc('media_files.updated_at')
            ->orderByDesc('tracks.id')
            ->paginate(50);

        return response()->json([
            'items' => collect($tracks->items())
                ->map(fn (Track $track): array => $this->trackPayload($track))
                ->values(),
            'total' => $tracks->total(),
            'page' => $tracks->currentPage(),
            'perPage' => $tracks->perPage(),
            'lastPage' => $tracks->lastPage(),
        ]);
    }

    public function destroy(Track $track): JsonResponse
    {
        $this->purge(collect([$track->id]));

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function destroyMany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trackIds' => ['required', 'array', 'min:1', 'max:500'],
            'trackIds.*' => ['integer', 'distinct', 'exists:tracks,id'],
        ]);
        $trackIds = collect($validated['trackIds'])
            ->map(fn (int $trackId): int => $trackId)
            ->values();

        $this->purge($trackIds);

        return response()->json(['deleted' => $trackIds->count()]);
    }

    /** @param Collection<int, int> $trackIds */
    private function purge(Collection $trackIds): void
    {
        $tracks = Track::query()
            ->with('mediaFile:id,status')
            ->whereKey($trackIds->all())
            ->get(['id', 'album_id', 'media_file_id']);

        abort_if(
            $tracks->count() !== $trackIds->count()
                || $tracks->contains(
                    fn (Track $track): bool => $track->mediaFile?->status !== MediaFileStatus::Missing,
                ),
            Response::HTTP_CONFLICT,
            'Only unavailable tracks can be permanently deleted.',
        );

        $playlistIds = PlaylistItem::query()
            ->whereIn('track_id', $trackIds->all())
            ->distinct()
            ->pluck('playlist_id');
        $albumIds = $tracks->pluck('album_id')->unique()->values();
        $mediaFileIds = $tracks->pluck('media_file_id')->unique()->values();
        $artistIds = DB::table('artist_track')
            ->whereIn('track_id', $trackIds->all())
            ->pluck('artist_id')
            ->merge(
                Album::query()
                    ->whereKey($albumIds->all())
                    ->whereNotNull('primary_artist_id')
                    ->pluck('primary_artist_id'),
            )
            ->unique()
            ->values();
        $genreIds = DB::table('genre_track')
            ->whereIn('track_id', $trackIds->all())
            ->pluck('genre_id')
            ->unique()
            ->values();

        DB::transaction(function () use (
            $albumIds,
            $artistIds,
            $genreIds,
            $mediaFileIds,
            $playlistIds,
        ): void {
            MediaFile::query()->whereKey($mediaFileIds->all())->delete();
            $this->normalizePlaylistPositions($playlistIds);

            Album::query()
                ->whereKey($albumIds->all())
                ->whereDoesntHave('mediaFiles')
                ->delete();
            Artist::query()
                ->whereKey($artistIds->all())
                ->whereDoesntHave('albums')
                ->whereDoesntHave('tracks')
                ->delete();
            Genre::query()
                ->whereKey($genreIds->all())
                ->whereDoesntHave('tracks')
                ->delete();
        });

        $this->playlistSynchronizationDispatcher->playlists($playlistIds);
    }

    /** @param Collection<int, int> $playlistIds */
    private function normalizePlaylistPositions(Collection $playlistIds): void
    {
        Playlist::query()
            ->whereKey($playlistIds->all())
            ->each(function (Playlist $playlist): void {
                $playlist->items()
                    ->orderBy('position')
                    ->orderBy('id')
                    ->pluck('id')
                    ->each(function (int $itemId, int $position): void {
                        PlaylistItem::query()
                            ->whereKey($itemId)
                            ->update(['position' => $position]);
                    });
            });
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        foreach (preg_split('/\s+/u', $search, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            $pattern = '%'.$this->escapeLike($term).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query
                    ->where('tracks.title', 'ilike', $pattern)
                    ->orWhere('media_files.relative_path', 'ilike', $pattern)
                    ->orWhereHas('album', fn (Builder $albums) => $albums->where('title', 'ilike', $pattern))
                    ->orWhereHas('artists', fn (Builder $artists) => $artists->where('name', 'ilike', $pattern));
            });
        }

        return $query;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** @return array<string, mixed> */
    private function trackPayload(Track $track): array
    {
        return [
            'id' => $track->id,
            'title' => $track->title,
            'album' => $track->album ? [
                'id' => $track->album->id,
                'title' => $track->album->title,
            ] : null,
            'artists' => $track->artists->map(fn (Artist $artist): array => [
                'id' => $artist->id,
                'name' => $artist->name,
            ])->values(),
            'libraryRoot' => $track->mediaFile?->libraryRoot ? [
                'id' => $track->mediaFile->libraryRoot->id,
                'name' => $track->mediaFile->libraryRoot->name,
            ] : null,
            'relativePath' => $track->mediaFile?->relative_path,
            'markedMissingAt' => $track->mediaFile?->updated_at?->toJSON(),
            'playlistCount' => (int) ($track->playlist_items_count ?? 0),
            'playCount' => (int) ($track->playStatistic?->play_count ?? 0),
        ];
    }
}
