<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Music\Enrichment\OnlineEnrichmentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnlineEnrichmentController extends Controller
{
    public function __construct(private readonly OnlineEnrichmentManager $enrichment)
    {
    }

    public function information(Request $request, Track $track): JsonResponse
    {
        $validated = $request->validate([
            'language' => ['sometimes', 'string', 'in:de,en'],
        ]);

        return response()->json($this->enrichment->informationForTrack(
            $track,
            $validated['language'] ?? 'en',
        ));
    }

    public function lyrics(Track $track): JsonResponse
    {
        return response()->json($this->enrichment->lyricsForTrack($track));
    }

    public function identity(Track $track): JsonResponse
    {
        return response()->json($this->enrichment->identityForTrack($track));
    }
}
