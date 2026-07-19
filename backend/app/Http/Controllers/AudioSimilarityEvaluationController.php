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
        $this->requireEnabledWorkspace();
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
        );

        abort_if($result === null, 404, 'This track has no compatible audio analysis artifact.');

        return response()->json($result);
    }

    public function storeFeedback(Request $request, Track $track, Track $candidate): JsonResponse
    {
        $this->requireEnabledWorkspace();
        $validated = $request->validate([
            'verdict' => ['required', 'in:relevant,irrelevant'],
        ]);

        return response()->json(
            $this->evaluator->recordFeedback($track->id, $candidate->id, $validated['verdict']),
        );
    }

    public function destroyFeedback(Track $track, Track $candidate): JsonResponse
    {
        $this->requireEnabledWorkspace();

        return response()->json(
            $this->evaluator->removeFeedback($track->id, $candidate->id),
        );
    }

    private function requireEnabledWorkspace(): void
    {
        abort_unless(
            ApplicationSetting::current()->audio_intelligence_enabled,
            409,
            'Enable audio intelligence before evaluating similarity.',
        );
    }
}
