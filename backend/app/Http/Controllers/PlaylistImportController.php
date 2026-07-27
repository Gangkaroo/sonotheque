<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistFolder;
use App\Music\Playlists\PlaylistFileSynchronizationDispatcher;
use App\Music\Playlists\PlaylistImportException;
use App\Music\Playlists\PlaylistImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlaylistImportController extends Controller
{
    public function __invoke(
        Request $request,
        PlaylistImporter $importer,
        PlaylistFileSynchronizationDispatcher $synchronizationDispatcher,
    ): JsonResponse {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'name' => ['required', 'string', 'max:255'],
            'folderId' => ['sometimes', 'nullable', 'integer', 'exists:playlist_folders,id'],
        ]);
        $folder = isset($validated['folderId'])
            ? PlaylistFolder::findOrFail($validated['folderId'])
            : null;

        try {
            $result = $importer->import(
                $validated['path'],
                trim($validated['name']),
                $folder,
            );
        } catch (PlaylistImportException $exception) {
            throw ValidationException::withMessages([
                'path' => $exception->getMessage(),
            ]);
        }

        $playlist = $result['playlist'];
        $synchronizationDispatcher->playlist($playlist);

        return response()->json([
            'playlist' => $this->playlistPayload($playlist),
            'totalEntries' => $result['totalEntries'],
            'importedCount' => $result['importedCount'],
            'unresolvedCount' => $result['unresolvedCount'],
            'warnings' => $result['warnings'],
        ], 201);
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
}
