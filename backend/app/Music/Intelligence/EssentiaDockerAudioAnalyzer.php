<?php

namespace App\Music\Intelligence;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

class EssentiaDockerAudioAnalyzer implements AudioAnalyzer
{
    /** @var list<array{source: string, destination: string}>|null */
    private ?array $sourceContainerMounts = null;

    public function __construct(
        private readonly string $image,
        private readonly string $modelPath,
        private readonly int $timeoutSeconds,
        private readonly float $cpuLimit,
        private readonly string $memoryLimit,
        private readonly int $preparationWorkers = 2,
        private readonly string $accelerator = 'cpu',
        private readonly bool $persistent = false,
        private readonly string $persistentContainerName = 'sonotheque-audio-analyzer',
        private readonly int $persistentStartupTimeoutSeconds = 90,
        private readonly ?string $mountSourceContainer = null,
    ) {
        if (! in_array($this->accelerator, ['cpu', 'cuda'], true)) {
            throw new InvalidArgumentException('The audio analyzer accelerator is invalid.');
        }
        if ($this->preparationWorkers < 1 || $this->preparationWorkers > 4) {
            throw new InvalidArgumentException(
                'The audio analyzer preparation worker count must be between 1 and 4.',
            );
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $this->persistentContainerName) !== 1) {
            throw new InvalidArgumentException('The persistent analyzer container name is invalid.');
        }
        if ($this->mountSourceContainer !== null
            && $this->mountSourceContainer !== 'self'
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $this->mountSourceContainer) !== 1) {
            throw new InvalidArgumentException('The analyzer mount source container is invalid.');
        }
    }

    public function health(): AudioAnalyzerHealth
    {
        if (! is_file($this->modelPath)) {
            return new AudioAnalyzerHealth(
                status: 'model_missing',
                message: 'Configure a readable Discogs EffNet model file.',
            );
        }

        if ($this->persistent) {
            $this->removeSiblingPersistentContainer();
            try {
                $state = $this->persistentContainerState();
                if ($state !== null && ($state['State']['Running'] ?? false) === true) {
                    $signature = $state['Config']['Labels']['sonotheque.audio-analyzer.signature']
                        ?? null;
                    if ($signature === $this->persistentSignature()) {
                        return $this->healthFromOutput($this->sendPersistentRequest([
                            'operation' => 'health',
                        ], 15));
                    }
                    $this->removePersistentContainer();
                }
            } catch (Throwable) {
                $this->removePersistentContainer();
            }
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

        return $this->healthFromOutput($result->output());
    }

    private function healthFromOutput(string $output): AudioAnalyzerHealth
    {
        try {
            $payload = $this->decode($output);
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
        if ($this->persistent) {
            try {
                return $this->analyzePersistentBatch($requests);
            } catch (Throwable $exception) {
                Log::warning('Persistent audio analyzer failed; retrying one-shot analysis.', [
                    'accelerator' => $this->accelerator,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $this->analyzeOneShotBatch($requests);
    }

    public function shutdown(): void
    {
        if (! $this->persistent) {
            return;
        }

        $this->removePersistentContainer();
        $this->removeSiblingPersistentContainer();
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     * @return list<AudioAnalyzerResult>
     */
    private function analyzeOneShotBatch(array $requests): array
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
            $mounts = [
                ...$mounts,
                ...$this->bindMount($sourcePath, $containerPath),
            ];
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

        return $this->resultsFromOutput($result->output());
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     * @return list<AudioAnalyzerResult>
     */
    private function analyzePersistentBatch(array $requests): array
    {
        [$rootPaths, $containerRequests] = $this->persistentRequests($requests);
        $this->ensurePersistentContainer($rootPaths);

        try {
            $output = $this->sendPersistentRequest([
                'operation' => 'analyze',
                'request' => [
                    'protocolVersion' => 1,
                    'items' => $containerRequests,
                ],
            ]);
        } catch (RuntimeException $exception) {
            $this->removePersistentContainer();
            $this->ensurePersistentContainer($rootPaths);
            $output = $this->sendPersistentRequest([
                'operation' => 'analyze',
                'request' => [
                    'protocolVersion' => 1,
                    'items' => $containerRequests,
                ],
            ]);
        }

        return $this->resultsFromOutput($output);
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     * @return array{list<string>, list<array<string, mixed>>}
     */
    private function persistentRequests(array $requests): array
    {
        $rootPaths = [];
        $containerRequests = [];

        foreach ($requests as $request) {
            $sourcePath = $request['path'] ?? null;
            $rootPath = $request['libraryRootPath'] ?? null;
            $relativePath = $request['relativePath'] ?? null;
            if (! is_string($sourcePath) || ! is_file($sourcePath)) {
                throw new RuntimeException('The sampled audio file is not readable.');
            }
            if (! is_string($rootPath) || ! is_dir($rootPath)) {
                throw new RuntimeException('The sampled audio file has no readable library root.');
            }
            if (! is_string($relativePath) || trim($relativePath) === '') {
                throw new RuntimeException('The sampled audio file has no relative library path.');
            }

            $normalizedRelativePath = str_replace('\\', '/', trim($relativePath));
            $segments = explode('/', $normalizedRelativePath);
            if (array_filter(
                $segments,
                static fn (string $segment): bool => $segment === ''
                    || $segment === '.'
                    || $segment === '..'
                    || str_contains($segment, ':'),
            ) !== []) {
                throw new RuntimeException('The sampled audio file has an unsafe relative path.');
            }

            if ($this->mountSourceContainer === null) {
                $rootPaths[$this->normalizedRootKey($rootPath)] = $rootPath;
            }
            $containerRequests[] = [
                'itemId' => $request['itemId'],
                'path' => $this->mountSourceContainer === null
                    ? $this->rootMountTarget($rootPath).'/'.$normalizedRelativePath
                    : rtrim(str_replace('\\', '/', $rootPath), '/').'/'.$normalizedRelativePath,
                'durationSeconds' => $request['durationSeconds'] ?? null,
            ];
        }

        return [array_values($rootPaths), $containerRequests];
    }

    /** @param  list<string>  $requestedRootPaths */
    private function ensurePersistentContainer(array $requestedRootPaths): void
    {
        $this->removeSiblingPersistentContainer();
        $signature = $this->persistentSignature();
        $state = $this->persistentContainerState();
        $rootPaths = $requestedRootPaths;

        if ($state !== null
            && ($state['Config']['Labels']['sonotheque.audio-analyzer.signature'] ?? null) === $signature) {
            if ($this->mountSourceContainer !== null
                && ($state['State']['Running'] ?? false) === true) {
                return;
            }
            $rootPaths = array_values(array_reduce(
                array_merge($this->mountedRootPaths($state), $requestedRootPaths),
                function (array $roots, string $rootPath): array {
                    $roots[$this->normalizedRootKey($rootPath)] = $rootPath;

                    return $roots;
                },
                [],
            ));
            if (($state['State']['Running'] ?? false) === true
                && $this->containsAllRoots($this->mountedRootPaths($state), $requestedRootPaths)) {
                return;
            }
        }

        if ($state !== null) {
            $this->removePersistentContainer();
        }

        $command = [
            'docker',
            'run',
            '--detach',
            '--name='.$this->persistentContainerName(),
            '--pull=never',
            '--network=none',
            '--cpus='.$this->cpuLimit,
            '--memory='.$this->memoryLimit,
            '--env=SONOTHEQUE_AUDIO_ACCELERATOR='.$this->accelerator,
            '--env=SONOTHEQUE_AUDIO_PREPARATION_WORKERS='.$this->preparationWorkers,
            '--label=sonotheque.audio-analyzer=true',
            '--label=sonotheque.audio-analyzer.group='.$this->persistentContainerName,
            '--label=sonotheque.audio-analyzer.accelerator='.$this->accelerator,
            '--label=sonotheque.audio-analyzer.signature='.$signature,
        ];
        if ($this->accelerator === 'cuda') {
            $command[] = '--gpus=all';
        }
        $command = [
            ...$command,
            ...$this->modelMount(),
        ];
        foreach ($rootPaths as $rootPath) {
            $command = [
                ...$command,
                ...$this->bindMount($rootPath, $this->rootMountTarget($rootPath)),
            ];
        }
        if ($this->mountSourceContainer !== null) {
            foreach ($this->sourceContainerLibraryMounts() as $mount) {
                $command = [
                    ...$command,
                    ...$this->daemonBindMount($mount['source'], $mount['destination']),
                ];
            }
        }
        $command = [
            ...$command,
            $this->image,
            'serve',
            '--model',
            '/model/model.pb',
            '--socket',
            '/tmp/sonotheque-audio-analyzer.sock',
        ];

        $result = Process::timeout(60)->run($command);
        if (! $result->successful()) {
            throw new RuntimeException($this->processError($result->errorOutput()));
        }

        $deadline = microtime(true) + max(10, $this->persistentStartupTimeoutSeconds);
        $lastError = 'The persistent analyzer did not become ready.';
        while (microtime(true) < $deadline) {
            try {
                $payload = $this->decode($this->sendPersistentRequest(['operation' => 'health'], 10));
                if (($payload['status'] ?? null) === 'ready') {
                    return;
                }
                $lastError = is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : $lastError;
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }
            usleep(250_000);
        }

        $logs = Process::timeout(10)->run([
            'docker',
            'logs',
            '--tail=40',
            $this->persistentContainerName(),
        ]);
        $this->removePersistentContainer();
        throw new RuntimeException(trim($logs->errorOutput().$logs->output()) ?: $lastError);
    }

    /** @param  array<string, mixed>  $payload */
    private function sendPersistentRequest(array $payload, ?int $timeoutSeconds = null): string
    {
        try {
            $input = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The persistent analyzer request could not be encoded.',
                previous: $exception,
            );
        }

        $result = Process::timeout($timeoutSeconds ?? $this->timeoutSeconds)
            ->input($input)
            ->run([
                'docker',
                'exec',
                '--interactive',
                $this->persistentContainerName(),
                'python',
                '/opt/sonotheque/worker.py',
                'client',
                '--socket',
                '/tmp/sonotheque-audio-analyzer.sock',
            ]);
        if (! $result->successful()) {
            throw new RuntimeException($this->processError($result->errorOutput()));
        }

        return $result->output();
    }

    /** @return array<string, mixed>|null */
    private function persistentContainerState(): ?array
    {
        $result = Process::timeout(15)->run([
            'docker',
            'inspect',
            $this->persistentContainerName(),
        ]);
        if (! $result->successful()) {
            return null;
        }

        try {
            $containers = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Docker returned invalid persistent analyzer state.',
                previous: $exception,
            );
        }

        return is_array($containers) && is_array($containers[0] ?? null)
            ? $containers[0]
            : null;
    }

    /** @param  array<string, mixed>  $state
     *  @return list<string>
     */
    private function mountedRootPaths(array $state): array
    {
        return collect($state['Mounts'] ?? [])
            ->filter(
                static fn (mixed $mount): bool => is_array($mount)
                    && is_string($mount['Source'] ?? null)
                    && is_string($mount['Destination'] ?? null)
                    && str_starts_with($mount['Destination'], '/library/'),
            )
            ->pluck('Source')
            ->values()
            ->all();
    }

    /** @param  list<string>  $mounted
     *  @param  list<string>  $requested
     */
    private function containsAllRoots(array $mounted, array $requested): bool
    {
        $mountedKeys = array_fill_keys(
            array_map($this->normalizedRootKey(...), $mounted),
            true,
        );

        return collect($requested)->every(
            fn (string $rootPath): bool => isset($mountedKeys[$this->normalizedRootKey($rootPath)]),
        );
    }

    private function removePersistentContainer(): void
    {
        Process::timeout(30)->run([
            'docker',
            'rm',
            '--force',
            $this->persistentContainerName(),
        ]);
    }

    private function removeSiblingPersistentContainer(): void
    {
        $siblingAccelerator = $this->accelerator === 'cuda' ? 'cpu' : 'cuda';
        Process::timeout(30)->run([
            'docker',
            'rm',
            '--force',
            $this->persistentContainerName.'-'.$siblingAccelerator,
        ]);
    }

    private function persistentSignature(): string
    {
        $image = Process::timeout(30)->run([
            'docker',
            'image',
            'inspect',
            '--format={{.Id}}',
            $this->image,
        ]);
        if (! $image->successful()) {
            throw new RuntimeException($this->processError($image->errorOutput()));
        }

        try {
            return hash('sha256', json_encode([
                'persistentProtocolVersion' => 1,
                'image' => trim($image->output()),
                'modelPath' => $this->modelPath,
                'modelSize' => filesize($this->modelPath),
                'modelModifiedAt' => filemtime($this->modelPath),
                'accelerator' => $this->accelerator,
                'cpuLimit' => $this->cpuLimit,
                'memoryLimit' => $this->memoryLimit,
                'preparationWorkers' => $this->preparationWorkers,
                'mountSourceContainer' => $this->resolvedMountSourceContainer(),
                'sourceContainerLibraryMounts' => $this->mountSourceContainer === null
                    ? []
                    : $this->sourceContainerLibraryMounts(),
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The persistent analyzer identity could not be encoded.',
                previous: $exception,
            );
        }
    }

    private function rootMountTarget(string $rootPath): string
    {
        return '/library/'.substr(hash('sha256', $this->normalizedRootKey($rootPath)), 0, 16);
    }

    private function normalizedRootKey(string $rootPath): string
    {
        return mb_strtolower(rtrim(str_replace('\\', '/', $rootPath), '/'));
    }

    private function persistentContainerName(): string
    {
        return $this->persistentContainerName.'-'.$this->accelerator;
    }

    /** @return list<string> */
    private function baseCommand(): array
    {
        $command = [
            'docker',
            'run',
            '--rm',
            '--interactive',
            '--pull=never',
            '--network=none',
            '--cpus='.$this->cpuLimit,
            '--memory='.$this->memoryLimit,
            '--env=SONOTHEQUE_AUDIO_ACCELERATOR='.$this->accelerator,
            '--env=SONOTHEQUE_AUDIO_PREPARATION_WORKERS='.$this->preparationWorkers,
        ];

        if ($this->accelerator === 'cuda') {
            $command[] = '--gpus=all';
        }

        return $command;
    }

    /** @return list<string> */
    private function modelMount(): array
    {
        return $this->bindMount($this->modelPath, '/model/model.pb');
    }

    /** @return list<string> */
    private function bindMount(string $source, string $target): array
    {
        return $this->daemonBindMount($this->daemonSourcePath($source), $target);
    }

    /** @return list<string> */
    private function daemonBindMount(string $source, string $target): array
    {
        return ['--volume', "{$source}:{$target}:ro"];
    }

    private function daemonSourcePath(string $containerPath): string
    {
        if ($this->mountSourceContainer === null) {
            return $containerPath;
        }

        $normalizedPath = $this->normalizedContainerPath($containerPath);
        foreach ($this->sourceContainerMounts() as $mount) {
            $destination = $mount['destination'];
            if ($normalizedPath !== $destination
                && ! str_starts_with($normalizedPath, $destination.'/')) {
                continue;
            }

            $suffix = ltrim(substr($normalizedPath, strlen($destination)), '/');
            $source = rtrim(str_replace('\\', '/', $mount['source']), '/');

            return $suffix === '' ? $source : $source.'/'.$suffix;
        }

        throw new RuntimeException(
            "The analyzer path [{$containerPath}] is not inside a mounted package path.",
        );
    }

    /** @return list<array{source: string, destination: string}> */
    private function sourceContainerLibraryMounts(): array
    {
        return array_values(array_filter(
            $this->sourceContainerMounts(),
            static fn (array $mount): bool => preg_match(
                '#^/music/root-[1-9][0-9]*$#',
                $mount['destination'],
            ) === 1,
        ));
    }

    /** @return list<array{source: string, destination: string}> */
    private function sourceContainerMounts(): array
    {
        if ($this->sourceContainerMounts !== null) {
            return $this->sourceContainerMounts;
        }

        $container = $this->resolvedMountSourceContainer();
        if ($container === null) {
            return $this->sourceContainerMounts = [];
        }

        $result = Process::timeout(15)->run(['docker', 'inspect', $container]);
        if (! $result->successful()) {
            throw new RuntimeException(
                'Docker could not inspect the packaged analysis worker: '
                .$this->processError($result->errorOutput()),
            );
        }

        try {
            $containers = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Docker returned invalid packaged analysis worker state.',
                previous: $exception,
            );
        }

        $mounts = collect($containers[0]['Mounts'] ?? [])
            ->filter(static fn (mixed $mount): bool => is_array($mount)
                && is_string($mount['Source'] ?? null)
                && is_string($mount['Destination'] ?? null))
            ->map(fn (array $mount): array => [
                'source' => $mount['Source'],
                'destination' => $this->normalizedContainerPath($mount['Destination']),
            ])
            ->sortByDesc(static fn (array $mount): int => strlen($mount['destination']))
            ->values()
            ->all();

        return $this->sourceContainerMounts = $mounts;
    }

    private function resolvedMountSourceContainer(): ?string
    {
        if ($this->mountSourceContainer === null) {
            return null;
        }

        return $this->mountSourceContainer === 'self'
            ? gethostname()
            : $this->mountSourceContainer;
    }

    private function normalizedContainerPath(string $path): string
    {
        return '/'.trim(str_replace('\\', '/', $path), '/');
    }

    /** @return list<AudioAnalyzerResult> */
    private function resultsFromOutput(string $output): array
    {
        try {
            $payload = $this->decode($output);
            if (($payload['protocolVersion'] ?? null) !== 1 || ! is_array($payload['results'] ?? null)) {
                $message = is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'The audio analyzer returned an incompatible response.';
                throw new RuntimeException($message);
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
