<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Models\LibraryRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FirstRunSetupController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload(ApplicationSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'step' => ['sometimes', 'integer', 'between:1,5'],
            'completed' => ['sometimes', 'boolean'],
        ]);

        if (($validated['completed'] ?? false) && ! LibraryRoot::query()->exists()) {
            return response()->json([
                'message' => 'Add at least one library root before completing setup.',
            ], 422);
        }

        $settings = ApplicationSetting::current();
        $settings->update([
            'setup_step' => $validated['step'] ?? $settings->setup_step,
            'setup_completed' => $validated['completed'] ?? $settings->setup_completed,
        ]);

        return response()->json($this->payload($settings->refresh()));
    }

    /** @return array{completed: bool, step: int, hasLibraryRoots: bool} */
    private function payload(ApplicationSetting $settings): array
    {
        return [
            'completed' => $settings->setup_completed,
            'step' => $settings->setup_step,
            'hasLibraryRoots' => LibraryRoot::query()->exists(),
        ];
    }
}
