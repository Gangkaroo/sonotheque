<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistExportLocation;
use App\Music\Playlists\CustomPlaylistExporter;
use App\Music\Playlists\PlaylistExportException;
use App\Support\LibraryRootScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomPlaylistExportController extends Controller
{
    public function show(
        Request $request,
        Playlist $playlist,
        CustomPlaylistExporter $exporter,
        LibraryRootScope $libraryRootScope,
    ): JsonResponse {
        return response()->json($exporter->configuration(
            $playlist,
            $libraryRootScope->id($request),
        ));
    }

    public function store(
        Request $request,
        Playlist $playlist,
        CustomPlaylistExporter $exporter,
        LibraryRootScope $libraryRootScope,
    ): JsonResponse {
        $validated = $request->validate([
            'locationId' => ['required', 'integer', 'exists:playlist_export_locations,id'],
            'format' => ['required', 'string', 'in:m3u,m3u8'],
            'filename' => ['required', 'string', 'max:255'],
            'overwrite' => ['sometimes', 'boolean'],
        ]);
        $location = PlaylistExportLocation::findOrFail($validated['locationId']);

        try {
            return response()->json($exporter->export(
                $playlist,
                $location,
                $validated['format'],
                $validated['filename'],
                (bool) ($validated['overwrite'] ?? false),
                $libraryRootScope->id($request),
            ));
        } catch (PlaylistExportException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                $exception->status,
            );
        }
    }
}
