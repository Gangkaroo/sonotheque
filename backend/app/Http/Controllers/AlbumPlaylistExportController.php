<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Music\Playlists\AlbumPlaylistExporter;
use App\Music\Playlists\PlaylistExportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlbumPlaylistExportController extends Controller
{
    public function show(
        Album $album,
        AlbumPlaylistExporter $exporter,
    ): JsonResponse {
        return response()->json($exporter->configuration($album));
    }

    public function store(
        Request $request,
        Album $album,
        AlbumPlaylistExporter $exporter,
    ): JsonResponse {
        $validated = $request->validate([
            'format' => ['required', 'string', 'in:m3u,m3u8'],
            'filename' => ['required', 'string', 'max:255'],
            'overwrite' => ['sometimes', 'boolean'],
        ]);

        try {
            return response()->json($exporter->export(
                $album,
                $validated['format'],
                $validated['filename'],
                (bool) ($validated['overwrite'] ?? false),
            ));
        } catch (PlaylistExportException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                $exception->status,
            );
        }
    }
}
