<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\Playlist;
use App\Models\PlaylistFolder;
use App\Models\PlaylistItem;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlaylistsController extends Controller
{
    public function folders(): JsonResponse
    {
        $folders = PlaylistFolder::query()
            ->withCount('playlists')
            ->orderBy('name')
            ->get()
            ->map(fn (PlaylistFolder $folder) => $this->folderPayload($folder));

        return response()->json(['items' => $folders]);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:playlist_folders,name'],
        ]);

        $folder = PlaylistFolder::create($validated);

        return response()->json($this->folderPayload($folder->loadCount('playlists')), 201);
    }

    public function updateFolder(Request $request, PlaylistFolder $folder): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', "unique:playlist_folders,name,{$folder->id}"],
        ]);

        $folder->update($validated);

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
        $playlist->load(['folder:id,name', 'items.track.album:id,title', 'items.track.artists:id,name'])
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

        $item = DB::transaction(function () use ($playlist, $track, $validated) {
            $maxPosition = $playlist->items()->max('position');
            $position = $validated['position'] ?? ($maxPosition === null ? 0 : (int) $maxPosition + 1);

            if (array_key_exists('position', $validated)) {
                $playlist->items()->where('position', '>=', $position)->increment('position');
            }

            return $playlist->items()->create([
                'track_id' => $track->id,
                'position' => $position,
            ]);
        });

        $item->load(['track.album:id,title', 'track.artists:id,name']);

        return response()->json($this->itemPayload($item), 201);
    }

    public function removeItem(Playlist $playlist, PlaylistItem $item): JsonResponse
    {
        abort_unless($item->playlist_id === $playlist->id, 404);

        $item->delete();
        $this->normalizeItemPositions($playlist);

        return response()->json(null, 204);
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
            'playlistCount' => $folder->playlists_count ?? 0,
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
            'track' => $this->trackPayload($item->track),
            'createdAt' => $item->created_at?->toJSON(),
            'updatedAt' => $item->updated_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function trackPayload(Track $track): array
    {
        return [
            'id' => $track->id,
            'title' => $track->title,
            'streamUrl' => "/api/tracks/{$track->id}/stream",
            'durationMs' => $track->duration_ms,
            'trackNumber' => $track->track_number,
            'discNumber' => $track->disc_number,
            'album' => $track->album ? [
                'id' => $track->album->id,
                'title' => $track->album->title,
            ] : null,
            'artists' => $track->artists->map(fn (Artist $artist) => [
                'id' => $artist->id,
                'name' => $artist->name,
            ])->values(),
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
}
