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
            'synchronizeWithFileTags' => ['required', 'boolean'],
        ]);
        $settings = ApplicationSetting::current();
        $settings->update([
            'import_play_statistics_from_tags' => $validated['synchronizeWithFileTags'],
            'export_play_statistics_to_tags' => $validated['synchronizeWithFileTags'],
        ]);

        return response()->json($this->payload($settings));
    }

    /** @return array{synchronizeWithFileTags: bool, supportedExportFormats: list<string>} */
    private function payload(ApplicationSetting $settings): array
    {
        return [
            'synchronizeWithFileTags' => $settings->synchronizesPlaybackStatisticsWithTags(),
            'supportedExportFormats' => ['mp3'],
        ];
    }
}
