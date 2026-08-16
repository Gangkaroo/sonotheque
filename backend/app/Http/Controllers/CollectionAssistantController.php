<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Music\Assistant\CollectionAssistantConversation;
use App\Music\Assistant\CollectionAssistantConversationException;
use App\Music\Assistant\CollectionAssistantProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CollectionAssistantController extends Controller
{
    public function __construct(private readonly CollectionAssistantConversation $conversation)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:1', 'max:1000'],
            'history' => ['sometimes', 'array', 'max:8'],
            'history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'history.*.content' => ['required', 'string', 'min:1', 'max:2000'],
            'libraryRoot' => [
                'nullable',
                'integer',
                Rule::exists('library_roots', 'id')->where('enabled', true),
            ],
            'locale' => ['sometimes', Rule::in(['de', 'en'])],
        ]);
        $settings = ApplicationSetting::current();
        if (! $settings->collection_assistant_enabled) {
            return $this->error('assistant_disabled', 409);
        }
        $model = trim((string) $settings->collection_assistant_model);
        if ($model === '') {
            return $this->error('model_not_selected', 409);
        }

        $timeoutSeconds = max(
            1,
            (int) config('sonotheque.collection_assistant.test_timeout_seconds', 120),
        );
        set_time_limit($timeoutSeconds + 10);

        try {
            $result = $this->conversation->ask(
                $model,
                trim($validated['question']),
                isset($validated['libraryRoot']) ? (int) $validated['libraryRoot'] : null,
                collect($validated['history'] ?? [])->map(fn (array $message): array => [
                    'role' => $message['role'],
                    'content' => trim($message['content']),
                ])->all(),
                $validated['locale'] ?? 'en',
            );

            return response()->json($result);
        } catch (CollectionAssistantProviderException $exception) {
            return $this->error($exception->errorCode, 503);
        } catch (CollectionAssistantConversationException $exception) {
            return $this->error($exception->errorCode, 502);
        }
    }

    private function error(string $errorCode, int $status): JsonResponse
    {
        return response()->json([
            'message' => 'The Collection Assistant could not answer the question.',
            'errorCode' => $errorCode,
        ], $status);
    }
}
