<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaybackStatisticsSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload(ApplicationSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'synchronizeWithFileTags' => ['sometimes', 'required', 'boolean'],
            'synchronizeRatingsWithFileTags' => ['sometimes', 'required', 'boolean'],
        ]);
        $settings = ApplicationSetting::current();
        if (array_key_exists('synchronizeWithFileTags', $validated)) {
            $settings->import_play_statistics_from_tags = $validated['synchronizeWithFileTags'];
            $settings->export_play_statistics_to_tags = $validated['synchronizeWithFileTags'];
        }
        if (array_key_exists('synchronizeRatingsWithFileTags', $validated)) {
            $settings->synchronize_ratings_with_tags = $validated['synchronizeRatingsWithFileTags'];
        }
        $settings->save();

        return response()->json($this->payload($settings));
    }

    /** @return array{synchronizeWithFileTags: bool, synchronizeRatingsWithFileTags: bool, supportedExportFormats: list<string>} */
    private function payload(ApplicationSetting $settings): array
    {
        return [
            'synchronizeWithFileTags' => $settings->synchronizesPlaybackStatisticsWithTags(),
            'synchronizeRatingsWithFileTags' => $settings->synchronizesRatingsWithTags(),
            'supportedExportFormats' => ['mp3'],
        ];
    }
}
