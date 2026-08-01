<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistFolder;
use App\Models\PlaylistItem;
use App\Models\Track;
use App\Music\Playlists\PlaylistFileSynchronizationDispatcher;
use App\Support\CatalogPayloads;
use App\Support\LibraryRootScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlaylistsController extends Controller
{
    public function __construct(
        private readonly CatalogPayloads $payloads,
        private readonly LibraryRootScope $libraryRootScope,
        private readonly PlaylistFileSynchronizationDispatcher $synchronizationDispatcher,
    ) {
    }

    public function folders(): JsonResponse
    {
        $folders = PlaylistFolder::query()
            ->with(['parent:id,name'])
            ->withCount(['playlists', 'children'])
            ->orderByRaw('parent_id is not null')
            ->orderBy('parent_id')
            ->orderBy('name')
            ->get()
            ->map(fn (PlaylistFolder $folder) => $this->folderPayload($folder));

        return response()->json(['items' => $folders]);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parentId' => ['sometimes', 'nullable', 'integer', 'exists:playlist_folders,id'],
        ]);

        $this->ensureUniqueFolderName($validated['name'], $validated['parentId'] ?? null);
        $folder = PlaylistFolder::create($this->folderAttributes($validated));

        return response()->json($this->folderPayload($folder->loadCount('playlists')), 201);
    }

    public function updateFolder(Request $request, PlaylistFolder $folder): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parentId' => ['sometimes', 'nullable', 'integer', Rule::notIn([$folder->id]), 'exists:playlist_folders,id'],
        ]);

        $parentId = array_key_exists('parentId', $validated) ? $validated['parentId'] : $folder->parent_id;
        $this->ensureFolderCanMoveTo($folder, $parentId);
        $this->ensureUniqueFolderName($validated['name'], $parentId, $folder->id);
        $playlistIds = $this->playlistIdsInFolderTree($folder);
        $folder->update($this->folderAttributes($validated));
        $this->synchronizationDispatcher->playlists($playlistIds);

        return response()->json($this->folderPayload($folder->loadCount('playlists')));
    }

    public function deleteFolder(PlaylistFolder $folder): JsonResponse
    {
        $playlistIds = $this->playlistIdsInFolderTree($folder);
        $folder->delete();
        $this->synchronizationDispatcher->playlists($playlistIds);

        return response()->json(null, 204);
    }

    public function playlists(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $query = Playlist::query()
            ->select('playlists.*')
            ->leftJoin('playlist_folders', 'playlist_folders.id', '=', 'playlists.playlist_folder_id')
            ->with('folder:id,name')
            ->withCount(['items' => fn (Builder $items) => $items->whereHas(
                'track',
                fn (Builder $tracks) => $this->libraryRootScope->tracks(
                    $tracks,
                    $libraryRootId,
                    availableOnly: false,
                ),
            )])
            ->orderByRaw('playlist_folders.id is null')
            ->orderByRaw('lower(playlist_folders.name)')
            ->orderByRaw('lower(playlists.name)');

        if ($request->filled('folder')) {
            $query->where('playlist_folder_id', $request->integer('folder'));
        }

        if ($request->boolean('withoutFolder')) {
            $query->whereNull('playlist_folder_id');
        }

        return response()->json([
            'items' => $query->get()->map(fn (Playlist $playlist) => $this->playlistPayload($playlist)),
        ]);
    }

    public function memberships(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trackIds' => ['required', 'array', 'min:1', 'max:500'],
            'trackIds.*' => ['integer', 'distinct', 'exists:tracks,id'],
        ]);
        $trackIds = collect($validated['trackIds'])
            ->map(fn (mixed $trackId): int => (int) $trackId)
            ->values();
        $libraryRootId = $this->libraryRootScope->id($request);
        $memberships = PlaylistItem::query()
            ->select([
                'playlist_items.track_id',
                'playlists.id as playlist_id',
                'playlists.name as playlist_name',
                'playlist_folders.id as folder_id',
                'playlist_folders.name as folder_name',
            ])
            ->selectRaw('MIN(playlist_items.id) AS first_item_id')
            ->selectRaw('COUNT(*) AS occurrence_count')
            ->join('playlists', 'playlists.id', '=', 'playlist_items.playlist_id')
            ->leftJoin('playlist_folders', 'playlist_folders.id', '=', 'playlists.playlist_folder_id')
            ->whereIn('playlist_items.track_id', $trackIds)
            ->whereHas(
                'track',
                fn (Builder $tracks) => $this->libraryRootScope->tracks(
                    $tracks,
                    $libraryRootId,
                    availableOnly: false,
                ),
            )
            ->groupBy([
                'playlist_items.track_id',
                'playlists.id',
                'playlists.name',
                'playlist_folders.id',
                'playlist_folders.name',
            ])
            ->orderByRaw('playlist_folders.id is null')
            ->orderByRaw('lower(playlist_folders.name)')
            ->orderByRaw('lower(playlists.name)')
            ->get()
            ->groupBy(fn (PlaylistItem $item): int => (int) $item->track_id);

        return response()->json([
            'items' => $trackIds->map(fn (int $trackId): array => [
                'trackId' => $trackId,
                'playlists' => $memberships->get($trackId, collect())
                    ->map(fn (PlaylistItem $item): array => [
                        'id' => (int) $item->playlist_id,
                        'name' => $item->playlist_name,
                        'folder' => $item->folder_id !== null ? [
                            'id' => (int) $item->folder_id,
                            'name' => $item->folder_name,
                        ] : null,
                        'firstItemId' => (int) $item->first_item_id,
                        'occurrenceCount' => (int) $item->occurrence_count,
                    ])
                    ->values(),
            ])->values(),
        ]);
    }

    public function playlist(Request $request, Playlist $playlist): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $playlist->load([
            'folder:id,name',
            'items' => fn ($items) => $items
                ->whereHas(
                    'track',
                    fn (Builder $tracks) => $this->libraryRootScope->tracks(
                        $tracks,
                        $libraryRootId,
                        availableOnly: false,
                    ),
                )
                ->with([
                    'track.album:id,title,original_release_year,artwork_id',
                    'track.album.personalMetadata',
                    'track.album.ownedCopies',
                    'track.artists:id,name',
                    'track.mediaFile:id,library_root_id,status',
                    'track.playStatistic:track_id,play_count,first_played_at,last_played_at',
                ]),
        ])->loadCount(['items' => fn (Builder $items) => $items->whereHas(
            'track',
            fn (Builder $tracks) => $this->libraryRootScope->tracks(
                $tracks,
                $libraryRootId,
                availableOnly: false,
            ),
        )]);

        return response()->json($this->playlistDetailPayload($playlist));
    }

    public function createPlaylist(Request $request): JsonResponse
    {
        $validated = $request->validate([
            ...$this->playlistRules(),
            'trackIds' => ['sometimes', 'array', 'min:1', 'max:500'],
            'trackIds.*' => ['integer', 'exists:tracks,id'],
        ]);
        $playlist = DB::transaction(function () use ($validated): Playlist {
            $playlist = Playlist::create($this->playlistAttributes($validated));
            if (isset($validated['trackIds'])) {
                $this->createPlaylistItems($playlist, $validated['trackIds'], null);
            }

            return $playlist->load(['folder:id,name'])->loadCount('items');
        });
        $this->synchronizationDispatcher->playlist($playlist);

        return response()->json($this->playlistPayload($playlist), 201);
    }

    public function updatePlaylist(Request $request, Playlist $playlist): JsonResponse
    {
        $validated = $request->validate($this->playlistRules(requiredName: false));
        $playlist->update($this->playlistAttributes($validated));
        $playlist->load(['folder:id,name'])->loadCount('items');
        $this->synchronizationDispatcher->playlist($playlist);

        return response()->json($this->playlistPayload($playlist));
    }

    public function deletePlaylist(Playlist $playlist): JsonResponse
    {
        $rootPath = $playlist->playlist_export_root_path;
        $relativePath = $playlist->playlist_export_relative_path;
        $playlist->delete();
        $this->synchronizationDispatcher->delete($rootPath, $relativePath);

        return response()->json(null, 204);
    }

    public function addTrack(Request $request, Playlist $playlist, Track $track): JsonResponse
    {
        $validated = $request->validate([
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $items = $this->createPlaylistItems($playlist, [$track->id], $validated['position'] ?? null);
        $item = $items->firstOrFail();
        $this->synchronizationDispatcher->playlist($playlist);

        return response()->json($this->itemPayload($item), 201);
    }

    public function addTracks(Request $request, Playlist $playlist): JsonResponse
    {
        $validated = $request->validate([
            'trackIds' => ['required', 'array', 'min:1', 'max:500'],
            'trackIds.*' => ['integer', 'exists:tracks,id'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $items = $this->createPlaylistItems($playlist, $validated['trackIds'], $validated['position'] ?? null);
        $this->synchronizationDispatcher->playlist($playlist);

        return response()->json([
            'items' => $items->map(fn (PlaylistItem $item) => $this->itemPayload($item))->values(),
        ], 201);
    }

    public function removeItem(Playlist $playlist, PlaylistItem $item): JsonResponse
    {
        abort_unless($item->playlist_id === $playlist->id, 404);

        $item->delete();
        $this->normalizeItemPositions($playlist);
        $this->synchronizationDispatcher->playlist($playlist);

        return response()->json(null, 204);
    }

    public function removeTrack(Playlist $playlist, Track $track): JsonResponse
    {
        $removedCount = DB::transaction(function () use ($playlist, $track): int {
            $removedCount = $playlist->items()->where('track_id', $track->id)->delete();
            abort_if($removedCount === 0, 404);
            $this->normalizeItemPositions($playlist);

            return $removedCount;
        });
        $this->synchronizationDispatcher->playlist($playlist);

        return response()->json(['removedCount' => $removedCount]);
    }

    public function removeItems(Request $request, Playlist $playlist): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['integer', 'distinct'],
        ]);

        $itemIds = collect($validated['items'])->values();
        $existingCount = $playlist->items()->whereKey($itemIds->all())->count();

        if ($existingCount !== $itemIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'All selected playlist items must belong to this playlist.',
            ]);
        }

        DB::transaction(function () use ($playlist, $itemIds) {
            $playlist->items()->whereKey($itemIds->all())->delete();
            $this->normalizeItemPositions($playlist);
        });
        $this->synchronizationDispatcher->playlist($playlist);

        return $this->playlist($request, $playlist);
    }

    public function reorderItems(Request $request, Playlist $playlist): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*' => ['integer', 'distinct'],
        ]);

        $requestedIds = collect($validated['items'])->values();
        $existingIds = $playlist->items()->orderBy('position')->pluck('id')->values();

        if ($requestedIds->sort()->values()->all() !== $existingIds->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'items' => 'The submitted item order must include every playlist item exactly once.',
            ]);
        }

        DB::transaction(function () use ($requestedIds) {
            $requestedIds->each(function (int $id, int $position) {
                PlaylistItem::query()->whereKey($id)->update(['position' => $position]);
            });
        });
        $this->synchronizationDispatcher->playlist($playlist);

        return $this->playlist($request, $playlist);
    }

    /** @return array<string, mixed> */
    private function folderPayload(PlaylistFolder $folder): array
    {
        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'parent' => $folder->parent ? [
                'id' => $folder->parent->id,
                'name' => $folder->parent->name,
            ] : null,
            'playlistCount' => $folder->playlists_count ?? 0,
            'childCount' => $folder->children_count ?? 0,
            'createdAt' => $folder->created_at?->toJSON(),
            'updatedAt' => $folder->updated_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function playlistPayload(Playlist $playlist): array
    {
        return [
            'id' => $playlist->id,
            'name' => $playlist->name,
            'description' => $playlist->description,
            'folder' => $playlist->folder ? [
                'id' => $playlist->folder->id,
                'name' => $playlist->folder->name,
            ] : null,
            'trackCount' => $playlist->items_count ?? 0,
            'createdAt' => $playlist->created_at?->toJSON(),
            'updatedAt' => $playlist->updated_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function playlistDetailPayload(Playlist $playlist): array
    {
        return [
            ...$this->playlistPayload($playlist),
            'items' => $playlist->items->map(fn (PlaylistItem $item) => $this->itemPayload($item))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function itemPayload(PlaylistItem $item): array
    {
        return [
            'id' => $item->id,
            'position' => $item->position,
            'track' => $this->payloads->trackSummary($item->track),
            'createdAt' => $item->created_at?->toJSON(),
            'updatedAt' => $item->updated_at?->toJSON(),
        ];
    }

    /** @return array<string, list<string>> */
    private function playlistRules(bool $requiredName = true): array
    {
        return [
            'name' => [$requiredName ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'folderId' => ['sometimes', 'nullable', 'integer', 'exists:playlist_folders,id'],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function folderAttributes(array $validated): array
    {
        $attributes = [
            'name' => $validated['name'],
        ];

        if (array_key_exists('parentId', $validated)) {
            $attributes['parent_id'] = $validated['parentId'];
        }

        return $attributes;
    }

    /** @param array<string, mixed> $validated */
    private function playlistAttributes(array $validated): array
    {
        $attributes = [];

        if (array_key_exists('name', $validated)) {
            $attributes['name'] = $validated['name'];
        }

        if (array_key_exists('description', $validated)) {
            $attributes['description'] = $validated['description'];
        }

        if (array_key_exists('folderId', $validated)) {
            $attributes['playlist_folder_id'] = $validated['folderId'];
        }

        return $attributes;
    }

    private function normalizeItemPositions(Playlist $playlist): void
    {
        $playlist->items()->orderBy('position')->pluck('id')->each(function (int $id, int $position) {
            PlaylistItem::query()->whereKey($id)->update(['position' => $position]);
        });
    }

    /**
     * @param  list<int>  $trackIds
     * @return \Illuminate\Database\Eloquent\Collection<int, PlaylistItem>
     */
    private function createPlaylistItems(Playlist $playlist, array $trackIds, ?int $position)
    {
        $items = DB::transaction(function () use ($playlist, $trackIds, $position) {
            $maxPosition = $playlist->items()->max('position');
            $startPosition = $position ?? ($maxPosition === null ? 0 : (int) $maxPosition + 1);

            if ($position !== null) {
                $playlist->items()->where('position', '>=', $startPosition)->increment('position', count($trackIds));
            }

            return collect($trackIds)->map(fn (int $trackId, int $offset) => $playlist->items()->create([
                'track_id' => $trackId,
                'position' => $startPosition + $offset,
            ]));
        });

        return PlaylistItem::query()
            ->whereIn('id', $items->pluck('id'))
            ->with([
                'track.album:id,title,original_release_year,artwork_id',
                'track.album.personalMetadata',
                'track.album.ownedCopies',
                'track.artists:id,name',
                'track.mediaFile:id,library_root_id,status',
                'track.playStatistic:track_id,play_count,first_played_at,last_played_at',
            ])
            ->orderBy('position')
            ->get();
    }

    private function ensureUniqueFolderName(string $name, ?int $parentId, ?int $ignoreId = null): void
    {
        $exists = PlaylistFolder::query()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('name', $name)
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'A playlist folder with this name already exists in the selected parent folder.',
            ]);
        }
    }

    private function ensureFolderCanMoveTo(PlaylistFolder $folder, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $descendantIds = $this->descendantFolderIds($folder);
        if (in_array($parentId, $descendantIds, true)) {
            throw ValidationException::withMessages([
                'parentId' => 'A playlist folder cannot be moved into one of its descendants.',
            ]);
        }
    }

    /** @return list<int> */
    private function playlistIdsInFolderTree(PlaylistFolder $folder): array
    {
        return Playlist::query()
            ->whereIn('playlist_folder_id', [$folder->id, ...$this->descendantFolderIds($folder)])
            ->pluck('id')
            ->map(fn (int $id): int => $id)
            ->all();
    }

    /** @return list<int> */
    private function descendantFolderIds(PlaylistFolder $folder): array
    {
        $descendantIds = [];
        $parentIds = [$folder->id];

        while ($parentIds !== []) {
            $childIds = PlaylistFolder::query()
                ->whereIn('parent_id', $parentIds)
                ->pluck('id')
                ->map(fn (int $id): int => $id)
                ->all();
            $newIds = array_values(array_diff($childIds, $descendantIds));
            if ($newIds === []) {
                break;
            }

            $descendantIds = [...$descendantIds, ...$newIds];
            $parentIds = $newIds;
        }

        return $descendantIds;
    }
}
