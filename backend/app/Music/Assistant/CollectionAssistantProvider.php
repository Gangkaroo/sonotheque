<?php

namespace App\Music\Assistant;

interface CollectionAssistantProvider
{
    /**
     * @return list<array{
     *     name: string,
     *     size: int|null,
     *     parameterSize: string|null,
     *     quantization: string|null,
     *     family: string|null
     * }>
     */
    public function models(): array;

    /** @return array{model: string, toolCalling: bool} */
    public function test(string $model): array;

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    public function chat(string $model, array $messages, array $tools): array;
}
