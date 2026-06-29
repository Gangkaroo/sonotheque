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
            'importFromFileTags' => ['required', 'boolean'],
        ]);
        $settings = ApplicationSetting::current();
        $settings->update([
            'import_play_statistics_from_tags' => $validated['importFromFileTags'],
        ]);

        return response()->json($this->payload($settings));
    }

    /** @return array{importFromFileTags: bool, exportToFileTags: bool} */
    private function payload(ApplicationSetting $settings): array
    {
        return [
            'importFromFileTags' => $settings->import_play_statistics_from_tags,
            'exportToFileTags' => $settings->export_play_statistics_to_tags,
        ];
    }
}
