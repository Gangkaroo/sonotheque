<?php

namespace App\Music\Intelligence;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class EssentiaCliAudioAnalyzer implements AudioAnalyzer
{
    public function __construct(
        private readonly string $pythonBinary,
        private readonly string $workerScript,
        private readonly string $modelPath,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function health(): AudioAnalyzerHealth
    {
        if (! is_file($this->workerScript)) {
            return new AudioAnalyzerHealth(
                status: 'not_configured',
                message: 'The configured audio analyzer worker script does not exist.',
            );
        }

        $result = Process::timeout(30)->run($this->command('health'));
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

    public function shutdown(): void
    {
    }

    public function analyzeBatch(array $requests): array
    {
        try {
            $input = json_encode([
                'protocolVersion' => 1,
                'items' => $requests,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The audio analyzer request could not be encoded.', previous: $exception);
        }

        $result = Process::timeout($this->timeoutSeconds)
            ->input($input)
            ->run($this->command('analyze-batch'));

        if (! $result->successful()) {
            throw new RuntimeException($this->processError($result->errorOutput()));
        }

        try {
            $payload = $this->decode($result->output());
        } catch (JsonException $exception) {
            throw new RuntimeException('The audio analyzer returned malformed JSON.', previous: $exception);
        }

        if (($payload['protocolVersion'] ?? null) !== 1 || ! is_array($payload['results'] ?? null)) {
            throw new RuntimeException('The audio analyzer returned an incompatible response.');
        }

        try {
            return array_map(
                static fn (mixed $item): AudioAnalyzerResult => AudioAnalyzerResult::fromArray(
                    is_array($item) ? $item : [],
                ),
                array_values($payload['results']),
            );
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    /** @return list<string> */
    private function command(string $operation): array
    {
        $command = [
            $this->pythonBinary,
            $this->workerScript,
            $operation,
        ];

        if ($this->modelPath !== '') {
            $command[] = '--model';
            $command[] = $this->modelPath;
        }

        return $command;
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
            ? 'The audio analyzer process failed.'
            : mb_substr($message, 0, 2000);
    }
}
