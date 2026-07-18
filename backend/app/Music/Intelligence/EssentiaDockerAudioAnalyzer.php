<?php

namespace App\Music\Intelligence;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class EssentiaDockerAudioAnalyzer implements AudioAnalyzer
{
    public function __construct(
        private readonly string $image,
        private readonly string $modelPath,
        private readonly int $timeoutSeconds,
        private readonly float $cpuLimit,
        private readonly string $memoryLimit,
    ) {
    }

    public function health(): AudioAnalyzerHealth
    {
        if (! is_file($this->modelPath)) {
            return new AudioAnalyzerHealth(
                status: 'model_missing',
                message: 'Configure a readable Discogs EffNet model file.',
            );
        }

        $result = Process::timeout(60)->run([
            ...$this->baseCommand(),
            ...$this->modelMount(),
            $this->image,
            'health',
            '--model',
            '/model/model.pb',
        ]);
        if (! $result->successful()) {
            return new AudioAnalyzerHealth(
                status: 'error',
                message: $this->processError($result->errorOutput()),
            );
        }

        try {
            $payload = $this->decode($result->output());
            $status = $payload['status'] ?? null;
            if (! is_string($status) || ! in_array($status, AudioAnalyzerHealth::STATUSES, true)) {
                throw new InvalidArgumentException('The audio analyzer health status is invalid.');
            }

            return new AudioAnalyzerHealth(
                status: $status,
                message: is_string($payload['message'] ?? null) ? $payload['message'] : null,
                profile: is_array($payload['profile'] ?? null)
                    ? AnalyzerProfile::fromArray($payload['profile'])
                    : null,
            );
        } catch (InvalidArgumentException|JsonException $exception) {
            return new AudioAnalyzerHealth(
                status: 'incompatible',
                message: $exception->getMessage(),
            );
        }
    }

    public function analyzeBatch(array $requests): array
    {
        $containerRequests = [];
        $mounts = [];

        foreach (array_values($requests) as $index => $request) {
            $sourcePath = $request['path'];
            if (! is_file($sourcePath)) {
                throw new RuntimeException("The sampled audio file [{$sourcePath}] is not readable.");
            }

            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
            $containerPath = '/audio/'.($index + 1).($extension === '' ? '' : '.'.$extension);
            $mounts[] = '--mount';
            $mounts[] = $this->bindMount($sourcePath, $containerPath);
            $containerRequests[] = [
                ...$request,
                'path' => $containerPath,
            ];
        }

        try {
            $input = json_encode([
                'protocolVersion' => 1,
                'items' => $containerRequests,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The audio analyzer request could not be encoded.', previous: $exception);
        }

        $result = Process::timeout($this->timeoutSeconds)
            ->input($input)
            ->run([
                ...$this->baseCommand(),
                ...$this->modelMount(),
                ...$mounts,
                $this->image,
                'analyze-batch',
                '--model',
                '/model/model.pb',
            ]);

        if (! $result->successful()) {
            throw new RuntimeException($this->processError($result->errorOutput()));
        }

        try {
            $payload = $this->decode($result->output());
            if (($payload['protocolVersion'] ?? null) !== 1 || ! is_array($payload['results'] ?? null)) {
                throw new RuntimeException('The audio analyzer returned an incompatible response.');
            }

            return array_map(
                static fn (mixed $item): AudioAnalyzerResult => AudioAnalyzerResult::fromArray(
                    is_array($item) ? $item : [],
                ),
                array_values($payload['results']),
            );
        } catch (InvalidArgumentException|JsonException $exception) {
            throw new RuntimeException('The audio analyzer returned an invalid response.', previous: $exception);
        }
    }

    /** @return list<string> */
    private function baseCommand(): array
    {
        return [
            'docker',
            'run',
            '--rm',
            '--interactive',
            '--pull=never',
            '--network=none',
            '--cpus='.$this->cpuLimit,
            '--memory='.$this->memoryLimit,
        ];
    }

    /** @return list<string> */
    private function modelMount(): array
    {
        return ['--mount', $this->bindMount($this->modelPath, '/model/model.pb')];
    }

    private function bindMount(string $source, string $target): string
    {
        if (str_contains($source, ',')) {
            throw new RuntimeException('Docker analyzer mount paths must not contain commas.');
        }

        return "type=bind,source={$source},target={$target},readonly";
    }

    /** @return array<string, mixed> */
    private function decode(string $output): array
    {
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new JsonException('The audio analyzer did not return an object.');
        }

        return $payload;
    }

    private function processError(string $errorOutput): string
    {
        $message = trim($errorOutput);

        return $message === ''
            ? 'The Docker audio analyzer process failed.'
            : mb_substr($message, 0, 2000);
    }
}
