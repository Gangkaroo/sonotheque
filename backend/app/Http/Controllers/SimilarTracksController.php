<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Models\Track;
use App\Music\Intelligence\AudioSimilarityEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SimilarTracksController extends Controller
{
    public function __construct(
        private readonly AudioSimilarityEvaluator $evaluator,
    ) {
    }

    public function __invoke(Request $request, Track $track): JsonResponse
    {
        abort_unless(
            ApplicationSetting::current()->audio_intelligence_enabled,
            409,
            'Enable audio intelligence before finding similar tracks.',
        );

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:25'],
            'excludeSameAlbum' => ['sometimes', 'boolean'],
            'excludeSameArtist' => ['sometimes', 'boolean'],
        ]);
        $result = $this->evaluator->evaluate(
            $track->id,
            $validated['limit'] ?? 10,
            $validated['excludeSameAlbum'] ?? true,
            $validated['excludeSameArtist'] ?? true,
        );

        abort_if($result === null, 404, 'This track has no compatible audio analysis artifact.');

        return response()->json($result);
    }
}
