<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Music\Catalog\RecordLabelSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlbumRecordLabelSuggestionController extends Controller
{
    public function index(Album $album, RecordLabelSuggestionService $suggestions): JsonResponse
    {
        return response()->json($suggestions->forAlbum($album));
    }

    public function confirm(
        Request $request,
        Album $album,
        RecordLabelSuggestionService $suggestions,
    ): JsonResponse {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:musicbrainz,discogs'],
            'sourceReference' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
        ]);

        return response()->json($suggestions->confirmSource(
            $album,
            $validated['provider'],
            trim($validated['sourceReference']),
        ));
    }

    public function select(
        Request $request,
        Album $album,
        RecordLabelSuggestionService $suggestions,
    ): JsonResponse {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:musicbrainz,discogs'],
            'sourceReference' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
        ]);

        return response()->json($suggestions->selectSource(
            $album,
            $validated['provider'],
            trim($validated['sourceReference']),
        ));
    }
}
