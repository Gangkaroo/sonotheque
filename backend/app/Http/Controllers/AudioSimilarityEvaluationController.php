<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Models\Track;
use App\Music\Intelligence\AudioSimilarityEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AudioSimilarityEvaluationController extends Controller
{
    public function __construct(
        private readonly AudioSimilarityEvaluator $evaluator,
    ) {
    }

    public function index(): JsonResponse
    {
        $this->requireEnabledWorkspace();

        return response()->json($this->evaluator->overview());
    }

    public function show(Request $request, Track $track): JsonResponse
    {
        $settings = $this->requireEnabledWorkspace();
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:25'],
            'excludeSameAlbum' => ['sometimes', 'boolean'],
            'excludeSameArtist' => ['sometimes', 'boolean'],
        ]);
        $result = $this->evaluator->evaluate(
            $track->id,
            $validated['limit'] ?? 10,
            $validated['excludeSameAlbum'] ?? false,
            $validated['excludeSameArtist'] ?? false,
            $settings->audioSimilarityReranking(),
        );

        abort_if($result === null, 404, 'This track has no compatible audio analysis artifact.');

        return response()->json($result);
    }

    public function storeFeedback(Request $request, Track $track, Track $candidate): JsonResponse
    {
        $this->requireEnabledWorkspace();
        $validated = $request->validate([
            'verdict' => ['required', 'in:relevant,irrelevant'],
            'excludeSameAlbum' => ['sometimes', 'boolean'],
            'excludeSameArtist' => ['sometimes', 'boolean'],
        ]);

        return response()->json(
            $this->evaluator->recordFeedback(
                $track->id,
                $candidate->id,
                $validated['verdict'],
                $validated['excludeSameAlbum'] ?? false,
                $validated['excludeSameArtist'] ?? false,
            ),
        );
    }

    public function destroyFeedback(
        Request $request,
        Track $track,
        Track $candidate,
    ): JsonResponse {
        $this->requireEnabledWorkspace();
        $validated = $request->validate([
            'excludeSameAlbum' => ['sometimes', 'boolean'],
            'excludeSameArtist' => ['sometimes', 'boolean'],
        ]);

        return response()->json(
            $this->evaluator->removeFeedback(
                $track->id,
                $candidate->id,
                $validated['excludeSameAlbum'] ?? false,
                $validated['excludeSameArtist'] ?? false,
            ),
        );
    }

    private function requireEnabledWorkspace(): ApplicationSetting
    {
        $settings = ApplicationSetting::current();
        abort_unless(
            $settings->audio_intelligence_enabled,
            409,
            'Enable audio intelligence before evaluating similarity.',
        );

        return $settings;
    }
}
