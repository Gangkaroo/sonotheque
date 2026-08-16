<?php

namespace App\Music\Assistant;

use JsonException;

class CollectionAssistantConversation
{
    private const MAX_ROUNDS = 4;

    private const MAX_TOOL_CALLS = 6;

    private const MAX_REFERENCES = 20;

    public function __construct(
        private readonly CollectionAssistantProvider $provider,
        private readonly CollectionAssistantToolRegistry $tools,
        private readonly CollectionAssistantDirectAnswer $directAnswer,
    ) {
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{answer: string, toolsUsed: list<string>, references: list<array{path: string, label: string}>, action?: array<string, mixed>}
     */
    public function ask(
        string $model,
        string $question,
        ?int $libraryRootId,
        array $history = [],
        string $locale = 'en',
    ): array {
        $directAnswer = $this->directAnswer->forQuestion($question, $libraryRootId, $locale);
        if ($directAnswer !== null) {
            return $directAnswer;
        }

        $messages = [
            [
                'role' => 'system',
                'content' => implode(' ', [
                    'You are the Sonotheque Collection Assistant.',
                    'Answer only about the user\'s local music collection.',
                    'Use the provided read-only tools for catalog facts and never invent results.',
                    'The server has fixed the active library-root scope; do not attempt to change it.',
                    'For albums by a named artist, use search_albums_by_artist instead of broad catalog search.',
                    'Use the listening tools for play history and rankings.',
                    'Use find_similar_tracks for requests about tracks that sound similar, and explain when the reference is ambiguous, not analyzed, or audio intelligence is disabled.',
                    'When the user explicitly asks to play similar tracks now, set find_similar_tracks action to play. When they explicitly ask to add similar tracks to the queue, set its action to queue. This only creates a preview that the user must confirm. Never claim that playback started or the queue changed; say that the preview is ready and awaits confirmation.',
                    'Call every tool needed for the answer in the first tool response.',
                    'For date-limited listening answers, explain that the result uses timestamped Sonotheque play events; all-time aggregate counts can also include imported file-tag statistics.',
                    'Keep the answer concise.',
                    'Do not include raw Sonotheque paths in the prose because the interface renders verified references separately.',
                ]),
            ],
        ];
        array_push($messages, ...$history);
        $messages[] = ['role' => 'user', 'content' => $question];
        $toolsUsed = [];
        $references = [];
        $action = null;
        $toolCallCount = 0;
        $toolDefinitions = $this->tools->definitions();

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $message = $this->provider->chat($model, $messages, $toolDefinitions);
            $toolCalls = $message['tool_calls'] ?? [];
            if (! is_array($toolCalls) || $toolCalls === []) {
                $answer = trim(is_string($message['content'] ?? null) ? $message['content'] : '');
                if ($answer === '') {
                    throw new CollectionAssistantConversationException('empty_response');
                }

                $result = [
                    'answer' => $answer,
                    'toolsUsed' => array_values(array_unique($toolsUsed)),
                    'references' => array_values($references),
                ];

                if ($action !== null) {
                    $result['action'] = $action;
                }

                return $result;
            }

            $messages[] = $this->messageForProvider($message);
            $executedTool = false;
            foreach ($toolCalls as $toolCall) {
                $toolCallCount++;
                if ($toolCallCount > self::MAX_TOOL_CALLS) {
                    throw new CollectionAssistantConversationException('tool_limit_exceeded');
                }

                $name = data_get($toolCall, 'function.name');
                $arguments = data_get($toolCall, 'function.arguments', []);
                if (! is_string($name) || ! is_array($arguments)) {
                    $name = is_string($name) ? $name : 'invalid_tool_call';
                    $result = ['error' => 'invalid_arguments'];
                } else {
                    try {
                        $result = $this->tools->execute($name, $arguments, $libraryRootId);
                        $toolsUsed[] = $name;
                        $executedTool = true;
                        $this->collectReferences($result, $references);
                        if ($action === null && isset($result['action']) && is_array($result['action'])) {
                            $action = $result['action'];
                        }
                        unset($result['action']);
                    } catch (CollectionAssistantToolException $exception) {
                        $result = ['error' => $exception->errorCode];
                    }
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_name' => $name,
                    'content' => $this->encode($result),
                ];
            }

            if ($executedTool) {
                $toolDefinitions = [];
            }
        }

        throw new CollectionAssistantConversationException('tool_round_limit_exceeded');
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new CollectionAssistantConversationException('tool_result_encoding_failed');
        }
    }

    /**
     * Preserve an empty JSON argument object when Laravel sends an Ollama tool
     * call back as conversation history.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function messageForProvider(array $message): array
    {
        foreach ($message['tool_calls'] as &$toolCall) {
            if (is_array($toolCall) && data_get($toolCall, 'function.arguments') === []) {
                $toolCall['function']['arguments'] = (object) [];
            }
        }
        unset($toolCall);

        return $message;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, array{path: string, label: string}>  $references
     */
    private function collectReferences(array $value, array &$references): void
    {
        if (count($references) >= self::MAX_REFERENCES) {
            return;
        }

        $path = $value['path'] ?? null;
        if (is_string($path) && $this->isSafeReferencePath($path)) {
            $references[$path] = [
                'path' => $path,
                'label' => $this->referenceLabel($value, $path),
            ];
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectReferences($child, $references);
            }
        }
    }

    private function isSafeReferencePath(string $path): bool
    {
        return preg_match(
            '#^/(?:artists|albums|tracks|musicians)(?:/\d+)?(?:\?[A-Za-z0-9_=&%+.,-]+)?$#',
            $path,
        ) === 1;
    }

    /** @param array<string, mixed> $value */
    private function referenceLabel(array $value, string $path): string
    {
        $title = trim(is_string($value['title'] ?? null) ? $value['title'] : '');
        $artist = trim(is_string($value['artist'] ?? null) ? $value['artist'] : '');
        if ($title !== '' && $artist !== '') {
            return $artist.' - '.$title;
        }

        foreach (['name', 'title'] as $key) {
            $label = trim(is_string($value[$key] ?? null) ? $value[$key] : '');
            if ($label !== '') {
                return $label;
            }
        }

        return $path;
    }
}
