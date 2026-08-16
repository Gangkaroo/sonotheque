<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Music\Assistant\CollectionAssistantProvider;
use App\Music\Assistant\CollectionAssistantProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionAssistantSettingsController extends Controller
{
    public function __construct(private readonly CollectionAssistantProvider $provider)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload(ApplicationSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'model' => ['nullable', 'string', 'max:255', 'required_if:enabled,true'],
        ]);
        $settings = ApplicationSetting::current();
        $settings->update([
            'collection_assistant_enabled' => $validated['enabled'],
            'collection_assistant_model' => filled($validated['model'] ?? null)
                ? trim($validated['model'])
                : null,
        ]);

        return response()->json($this->payload($settings->fresh()));
    }

    public function models(): JsonResponse
    {
        try {
            return response()->json([
                'status' => 'available',
                'models' => $this->provider->models(),
                'errorCode' => null,
            ]);
        } catch (CollectionAssistantProviderException $exception) {
            return response()->json([
                'status' => 'error',
                'models' => [],
                'errorCode' => $exception->errorCode,
            ]);
        }
    }

    public function test(Request $request): JsonResponse
    {
        $timeoutSeconds = max(
            1,
            (int) config('sonotheque.collection_assistant.test_timeout_seconds', 120),
        );
        set_time_limit($timeoutSeconds + 10);

        $validated = $request->validate([
            'model' => ['nullable', 'string', 'max:255'],
        ]);
        $model = filled($validated['model'] ?? null)
            ? trim($validated['model'])
            : ApplicationSetting::current()->collection_assistant_model;
        if (blank($model)) {
            return response()->json([
                'status' => 'not_configured',
                'model' => null,
                'toolCalling' => false,
                'errorCode' => 'model_not_selected',
            ]);
        }

        try {
            $result = $this->provider->test($model);

            return response()->json([
                'status' => 'available',
                ...$result,
                'errorCode' => null,
            ]);
        } catch (CollectionAssistantProviderException $exception) {
            return response()->json([
                'status' => 'error',
                'model' => $model,
                'toolCalling' => false,
                'errorCode' => $exception->errorCode,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(ApplicationSetting $settings): array
    {
        return [
            'enabled' => (bool) $settings->collection_assistant_enabled,
            'provider' => 'ollama',
            'model' => $settings->collection_assistant_model,
            'endpoint' => (string) config('sonotheque.collection_assistant.ollama_url'),
            'recommendedModel' => (string) config(
                'sonotheque.collection_assistant.recommended_model',
                'qwen3:4b',
            ),
        ];
    }
}
