<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistFolder;
use App\Models\PlaylistItem;
use App\Models\Track;
use App\Support\CatalogPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlaylistsController extends Controller
{
    public function __construct(private readonly CatalogPayloads $payloads)
    {
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
        $this->ensureUniqueFolderName($validated['name'], $parentId, $folder->id);
        $folder->update($this->folderAttributes($validated));

        return response()->json($this->folderPayload($folder->loadCount('playlists')));
    }

    public function deleteFolder(PlaylistFolder $folder): JsonResponse
    {
        $folder->delete();

        return response()->json(null, 204);
    }

    public function playlists(Request $request): JsonResponse
    {
        $query = Playlist::query()
            ->with('folder:id,name')
            ->withCount('items')
            ->orderBy('name');

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

    public function playlist(Playlist $playlist): JsonResponse
    {
        $playlist->load(['folder:id,name', 'items.track.album:id,title,original_release_year', 'items.track.artists:id,name'])
            ->loadCount('items');

        return response()->json($this->playlistDetailPayload($playlist));
    }

    public function createPlaylist(Request $request): JsonResponse
    {
        $validated = $request->validate($this->playlistRules());
        $playlist = Playlist::create($this->playlistAttributes($validated))
            ->load(['folder:id,name'])
            ->loadCount('items');

        return response()->json($this->playlistPayload($playlist), 201);
    }

    public function updatePlaylist(Request $request, Playlist $playlist): JsonResponse
    {
        $validated = $request->validate($this->playlistRules(requiredName: false));
        $playlist->update($this->playlistAttributes($validated));
        $playlist->load(['folder:id,name'])->loadCount('items');

        return response()->json($this->playlistPayload($playlist));
    }

    public function deletePlaylist(Playlist $playlist): JsonResponse
    {
        $playlist->delete();

        return response()->json(null, 204);
    }

    public function addTrack(Request $request, Playlist $playlist, Track $track): JsonResponse
    {
        $validated = $request->validate([
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $items = $this->createPlaylistItems($playlist, [$track->id], $validated['position'] ?? null);
        $item = $items->firstOrFail();

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

        return response()->json([
            'items' => $items->map(fn (PlaylistItem $item) => $this->itemPayload($item))->values(),
        ], 201);
    }

    public function removeItem(Playlist $playlist, PlaylistItem $item): JsonResponse
    {
        abort_unless($item->playlist_id === $playlist->id, 404);

        $item->delete();
        $this->normalizeItemPositions($playlist);

        return response()->json(null, 204);
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

        return $this->playlist($playlist);
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

        return $this->playlist($playlist);
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
            ->with(['track.album:id,title,original_release_year', 'track.artists:id,name'])
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
}
