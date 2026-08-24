<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogRatingController extends Controller
{
    public function updateAlbum(Request $request, Album $album): JsonResponse
    {
        return $this->update($request, $album);
    }

    public function clearAlbum(Album $album): JsonResponse
    {
        return $this->clear($album);
    }

    public function updateTrack(Request $request, Track $track): JsonResponse
    {
        return $this->update($request, $track);
    }

    public function clearTrack(Track $track): JsonResponse
    {
        return $this->clear($track);
    }

    private function update(Request $request, Model $model): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'numeric', 'min:0.5', 'max:5', 'multiple_of:0.5'],
        ]);
        $halfSteps = (int) round((float) $validated['rating'] * 2);

        $model->setAttribute('rating_half_steps', $halfSteps);
        $model->save();

        return response()->json([
            'id' => $model->getKey(),
            'rating' => $halfSteps / 2,
        ]);
    }

    private function clear(Model $model): JsonResponse
    {
        $model->setAttribute('rating_half_steps', null);
        $model->save();

        return response()->json(null, 204);
    }
}
