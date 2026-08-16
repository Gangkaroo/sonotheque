<?php

namespace App\Music\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OllamaCollectionAssistantProvider implements CollectionAssistantProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly int $testTimeoutSeconds,
        private readonly string $keepAlive,
        private readonly int $contextWindow,
        private readonly int $maxAnswerTokens,
    ) {
    }

    public function models(): array
    {
        try {
            $response = $this->request($this->timeoutSeconds)->get('/api/tags');
        } catch (ConnectionException $exception) {
            throw new CollectionAssistantProviderException($this->connectionErrorCode($exception), $exception);
        }
        $this->ensureSuccessful($response);
        $models = $response->json('models');
        if (! is_array($models)) {
            throw new CollectionAssistantProviderException('invalid_response');
        }

        return collect($models)
            ->filter(fn (mixed $model): bool => is_array($model) && is_string($model['name'] ?? null))
            ->map(function (array $model): array {
                $details = is_array($model['details'] ?? null) ? $model['details'] : [];

                return [
                    'name' => $model['name'],
                    'size' => is_numeric($model['size'] ?? null) ? (int) $model['size'] : null,
                    'parameterSize' => $this->nullableString($details['parameter_size'] ?? null),
                    'quantization' => $this->nullableString($details['quantization_level'] ?? null),
                    'family' => $this->nullableString($details['family'] ?? null),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function test(string $model): array
    {
        $response = $this->chatResponse([
                'model' => $model,
                'stream' => false,
                'think' => false,
                'keep_alive' => $this->keepAlive,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You must call the provided tool. Do not write a natural-language answer.',
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Use collection_status with scope "all" now.',
                    ],
                ],
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'collection_status',
                        'description' => 'Return a summary of the local Sonotheque collection.',
                        'parameters' => [
                            'type' => 'object',
                            'required' => ['scope'],
                            'properties' => [
                                'scope' => [
                                    'type' => 'string',
                                    'enum' => ['all'],
                                ],
                            ],
                        ],
                    ],
                ]],
                'options' => [
                    'temperature' => 0,
                    'num_predict' => 128,
                    'num_ctx' => $this->contextWindow,
                ],
            ]);

        $toolCalls = $response->json('message.tool_calls');
        $supportsTools = is_array($toolCalls) && collect($toolCalls)->contains(
            fn (mixed $call): bool => is_array($call)
                && data_get($call, 'function.name') === 'collection_status',
        );
        if (! $supportsTools) {
            throw new CollectionAssistantProviderException('tool_call_failed');
        }

        return [
            'model' => $model,
            'toolCalling' => true,
        ];
    }

    public function chat(string $model, array $messages, array $tools): array
    {
        $response = $this->chatResponse([
            'model' => $model,
            'stream' => false,
            'think' => false,
            'keep_alive' => $this->keepAlive,
            'messages' => $messages,
            'tools' => $tools,
            'options' => [
                'temperature' => 0,
                'num_predict' => $this->maxAnswerTokens,
                'num_ctx' => $this->contextWindow,
            ],
        ]);
        $message = $response->json('message');
        if (! is_array($message) || ($message['role'] ?? null) !== 'assistant') {
            throw new CollectionAssistantProviderException('invalid_response');
        }

        return [
            'role' => 'assistant',
            'content' => is_string($message['content'] ?? null) ? $message['content'] : '',
            'tool_calls' => is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [],
        ];
    }

    private function request(int $timeoutSeconds): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(min(5, $timeoutSeconds))
            ->timeout($timeoutSeconds);
    }

    /** @param array<string, mixed> $payload */
    private function chatResponse(array $payload): Response
    {
        try {
            $response = $this->request($this->testTimeoutSeconds)->post('/api/chat', $payload);
        } catch (ConnectionException $exception) {
            throw new CollectionAssistantProviderException($this->connectionErrorCode($exception), $exception);
        }
        $this->ensureSuccessful($response);

        return $response;
    }

    private function ensureSuccessful(Response $response): void
    {
        if (! $response->successful()) {
            Log::warning('Collection Assistant provider request failed.', [
                'status' => $response->status(),
                'response' => Str::limit($response->body(), 1000),
            ]);

            throw new CollectionAssistantProviderException(
                $response->status() === 404 ? 'model_not_found' : 'provider_error',
            );
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function connectionErrorCode(ConnectionException $exception): string
    {
        return Str::contains(Str::lower($exception->getMessage()), ['timed out', 'timeout'])
            ? 'provider_timeout'
            : 'connection_failed';
    }
}
