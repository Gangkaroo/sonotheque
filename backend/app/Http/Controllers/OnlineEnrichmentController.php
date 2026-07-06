<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Music\Enrichment\ArtistImageCache;
use App\Music\Enrichment\OnlineEnrichmentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    public function artistImage(Request $request, Track $track, ArtistImageCache $images): Response
    {
        $result = $this->enrichment->artistImageForTrack($track);
        $imageUrl = $result['data']['imageUrl'] ?? null;

        abort_unless(is_string($imageUrl), 404);

        return $images->response($imageUrl) ?? abort(404);
    }

    public function artistImageInformation(Track $track): JsonResponse
    {
        $result = $this->enrichment->artistImageForTrack($track);
        if (($result['status'] ?? null) === 'ready' && is_array($result['data'] ?? null)) {
            $result['data']['imageUrl'] = "/api/enrichment/tracks/{$track->id}/artist-image";
        }

        return response()->json($result);
    }
}
