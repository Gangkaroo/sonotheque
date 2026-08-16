<?php

namespace Tests\Unit;

use App\Music\Intelligence\EssentiaDockerAudioAnalyzer;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class EssentiaDockerAudioAnalyzerTest extends TestCase
{
    public function test_it_mounts_comma_containing_windows_paths_with_volume_syntax(): void
    {
        $directory = storage_path('framework/testing/audio, intelligence');
        $audioPath = $directory.'/track, one.mp3';
        $modelPath = $directory.'/model, one.pb';
        File::ensureDirectoryExists($directory);
        File::put($audioPath, 'audio');
        File::put($modelPath, 'model');
        Process::fake([
            '*' => Process::result(output: json_encode([
                'protocolVersion' => 1,
                'results' => [[
                    'itemId' => 10,
                    'status' => 'completed',
                    'embedding' => [0.1, 0.2],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        try {
            $analyzer = new EssentiaDockerAudioAnalyzer(
                image: 'sonotheque-audio-intelligence:test',
                modelPath: $modelPath,
                timeoutSeconds: 60,
                cpuLimit: 2,
                memoryLimit: '4g',
            );

            $results = $analyzer->analyzeBatch([[
                'itemId' => 10,
                'path' => $audioPath,
                'durationSeconds' => 120.0,
            ]]);

            $this->assertCount(1, $results);
            $this->assertSame(10, $results[0]->itemId);
            Process::assertRan(function (PendingProcess $process) use ($audioPath, $modelPath): bool {
                $command = $process->command;
                if (! is_array($command) || in_array('--mount', $command, true)) {
                    return false;
                }

                return in_array('--volume', $command, true)
                    && in_array("{$audioPath}:/audio/1.mp3:ro", $command, true)
                    && in_array("{$modelPath}:/model/model.pb:ro", $command, true);
            });
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_translates_packaged_container_paths_to_docker_mount_sources(): void
    {
        $directory = storage_path('framework/testing/packaged-audio-intelligence');
        $audioPath = $directory.'/track.mp3';
        $modelPath = $directory.'/model.pb';
        File::ensureDirectoryExists($directory);
        File::put($audioPath, 'audio');
        File::put($modelPath, 'model');
        $containerDirectory = '/'.trim(str_replace('\\', '/', $directory), '/');
        Process::fake(function (PendingProcess $process) use ($containerDirectory) {
            $command = $process->command;
            if (is_array($command) && $command === ['docker', 'inspect', 'packaged-worker']) {
                return Process::result(output: json_encode([[
                    'Mounts' => [[
                        'Source' => '/run/desktop/mnt/host/c/sonotheque-model-and-music',
                        'Destination' => $containerDirectory,
                    ], [
                        'Source' => '/var/run/docker.sock',
                        'Destination' => '/var/run/docker.sock',
                    ]],
                ]], JSON_THROW_ON_ERROR));
            }

            return Process::result(output: json_encode([
                'protocolVersion' => 1,
                'results' => [[
                    'itemId' => 10,
                    'status' => 'completed',
                    'embedding' => [0.1, 0.2],
                ]],
            ], JSON_THROW_ON_ERROR));
        });

        try {
            $analyzer = new EssentiaDockerAudioAnalyzer(
                image: 'sonotheque-audio-intelligence:test',
                modelPath: $modelPath,
                timeoutSeconds: 60,
                cpuLimit: 2,
                memoryLimit: '4g',
                mountSourceContainer: 'packaged-worker',
            );

            $analyzer->analyzeBatch([[
                'itemId' => 10,
                'path' => $audioPath,
                'durationSeconds' => 120.0,
            ]]);

            Process::assertRan(function (PendingProcess $process): bool {
                $command = $process->command;

                return is_array($command)
                    && array_slice($command, 0, 2) === ['docker', 'run']
                    && in_array(
                        '/run/desktop/mnt/host/c/sonotheque-model-and-music/track.mp3:/audio/1.mp3:ro',
                        $command,
                        true,
                    )
                    && in_array(
                        '/run/desktop/mnt/host/c/sonotheque-model-and-music/model.pb:/model/model.pb:ro',
                        $command,
                        true,
                    )
                    && ! collect($command)->contains(
                        fn (string $argument): bool => str_contains($argument, 'docker.sock'),
                    );
            });
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_exposes_all_gpus_only_for_the_cuda_accelerator(): void
    {
        $directory = storage_path('framework/testing/audio-intelligence-cuda');
        $audioPath = $directory.'/track.mp3';
        $modelPath = $directory.'/model.pb';
        File::ensureDirectoryExists($directory);
        File::put($audioPath, 'audio');
        File::put($modelPath, 'model');
        Process::fake([
            '*' => Process::result(output: json_encode([
                'protocolVersion' => 1,
                'results' => [[
                    'itemId' => 10,
                    'status' => 'completed',
                    'embedding' => [0.1, 0.2],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        try {
            $analyzer = new EssentiaDockerAudioAnalyzer(
                image: 'sonotheque-audio-intelligence:cuda',
                modelPath: $modelPath,
                timeoutSeconds: 60,
                cpuLimit: 2,
                memoryLimit: '4g',
                preparationWorkers: 3,
                accelerator: 'cuda',
            );

            $analyzer->analyzeBatch([[
                'itemId' => 10,
                'path' => $audioPath,
                'durationSeconds' => 120.0,
            ]]);

            Process::assertRan(
                fn (PendingProcess $process): bool => is_array($process->command)
                    && in_array('--gpus=all', $process->command, true)
                    && in_array(
                        '--env=SONOTHEQUE_AUDIO_ACCELERATOR=cuda',
                        $process->command,
                        true,
                    )
                    && in_array(
                        '--env=SONOTHEQUE_AUDIO_PREPARATION_WORKERS=3',
                        $process->command,
                        true,
                    ),
            );
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_cpu_analysis_does_not_request_cuda_devices(): void
    {
        $directory = storage_path('framework/testing/audio-intelligence-cpu');
        $audioPath = $directory.'/track.mp3';
        $modelPath = $directory.'/model.pb';
        File::ensureDirectoryExists($directory);
        File::put($audioPath, 'audio');
        File::put($modelPath, 'model');
        Process::fake([
            '*' => Process::result(output: json_encode([
                'protocolVersion' => 1,
                'results' => [[
                    'itemId' => 10,
                    'status' => 'completed',
                    'embedding' => [0.1, 0.2],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        try {
            $analyzer = new EssentiaDockerAudioAnalyzer(
                image: 'sonotheque-audio-intelligence:cpu',
                modelPath: $modelPath,
                timeoutSeconds: 60,
                cpuLimit: 2,
                memoryLimit: '4g',
                preparationWorkers: 2,
                accelerator: 'cpu',
            );

            $analyzer->analyzeBatch([[
                'itemId' => 10,
                'path' => $audioPath,
                'durationSeconds' => 120.0,
            ]]);

            Process::assertRan(function (PendingProcess $process): bool {
                $command = $process->command;

                return is_array($command)
                    && in_array(
                        '--env=SONOTHEQUE_AUDIO_ACCELERATOR=cpu',
                        $command,
                        true,
                    )
                    && in_array(
                        '--env=SONOTHEQUE_AUDIO_PREPARATION_WORKERS=2',
                        $command,
                        true,
                    )
                    && ! in_array('--gpus=all', $command, true);
            });
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_reuses_a_persistent_container_with_read_only_root_mounts(): void
    {
        $directory = storage_path('framework/testing/audio-intelligence-persistent');
        $rootPath = $directory.'/library';
        $audioPath = $rootPath.'/Artist/Album/track.mp3';
        $modelPath = $directory.'/model.pb';
        File::ensureDirectoryExists(dirname($audioPath));
        File::put($audioPath, 'audio');
        File::put($modelPath, 'model');
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            if (! is_array($command)) {
                return Process::result(exitCode: 1);
            }
            if (array_slice($command, 0, 3) === ['docker', 'image', 'inspect']) {
                return Process::result(output: "sha256:image\n");
            }
            if (array_slice($command, 0, 2) === ['docker', 'inspect']) {
                return Process::result(exitCode: 1);
            }
            if (array_slice($command, 0, 2) === ['docker', 'run']) {
                return Process::result(output: "container-id\n");
            }
            if (array_slice($command, 0, 2) === ['docker', 'exec']) {
                $request = json_decode((string) $process->input, true, flags: JSON_THROW_ON_ERROR);
                if (($request['operation'] ?? null) === 'health') {
                    return Process::result(output: json_encode([
                        'status' => 'ready',
                    ], JSON_THROW_ON_ERROR));
                }

                return Process::result(output: json_encode([
                    'protocolVersion' => 1,
                    'results' => [[
                        'itemId' => 10,
                        'status' => 'completed',
                        'embedding' => [0.1, 0.2],
                    ]],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result(exitCode: 1);
        });

        try {
            $analyzer = new EssentiaDockerAudioAnalyzer(
                image: 'sonotheque-audio-intelligence:cuda',
                modelPath: $modelPath,
                timeoutSeconds: 60,
                cpuLimit: 2,
                memoryLimit: '4g',
                accelerator: 'cuda',
                persistent: true,
            );

            $results = $analyzer->analyzeBatch([[
                'itemId' => 10,
                'path' => $audioPath,
                'durationSeconds' => 120.0,
                'libraryRootPath' => $rootPath,
                'relativePath' => 'Artist/Album/track.mp3',
            ]]);

            $this->assertCount(1, $results);
            $analyzer->shutdown();
            Process::assertRan(function (PendingProcess $process) use ($rootPath): bool {
                $command = $process->command;

                return is_array($command)
                    && array_slice($command, 0, 2) === ['docker', 'run']
                    && in_array('--detach', $command, true)
                    && collect($command)->contains(
                        fn (string $argument): bool => str_starts_with(
                            $argument,
                            "{$rootPath}:/library/",
                        ) && str_ends_with($argument, ':ro'),
                    );
            });
            Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
                && array_slice($process->command, 0, 2) === ['docker', 'exec']
                && str_contains((string) $process->input, '"operation":"analyze"'));
            Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
                && $process->command === [
                    'docker',
                    'rm',
                    '--force',
                    'sonotheque-audio-analyzer-cuda',
                ]);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_falls_back_to_one_shot_without_changing_accelerator(): void
    {
        $directory = storage_path('framework/testing/audio-intelligence-fallback');
        $rootPath = $directory.'/library';
        $audioPath = $rootPath.'/track.mp3';
        $modelPath = $directory.'/model.pb';
        File::ensureDirectoryExists($rootPath);
        File::put($audioPath, 'audio');
        File::put($modelPath, 'model');
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            if (is_array($command)
                && array_slice($command, 0, 2) === ['docker', 'run']
                && in_array('--rm', $command, true)) {
                return Process::result(output: json_encode([
                    'protocolVersion' => 1,
                    'results' => [[
                        'itemId' => 10,
                        'status' => 'completed',
                        'embedding' => [0.1, 0.2],
                    ]],
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result(exitCode: 1, errorOutput: 'persistent unavailable');
        });

        try {
            $analyzer = new EssentiaDockerAudioAnalyzer(
                image: 'sonotheque-audio-intelligence:cuda',
                modelPath: $modelPath,
                timeoutSeconds: 60,
                cpuLimit: 2,
                memoryLimit: '4g',
                accelerator: 'cuda',
                persistent: true,
            );

            $results = $analyzer->analyzeBatch([[
                'itemId' => 10,
                'path' => $audioPath,
                'durationSeconds' => 120.0,
                'libraryRootPath' => $rootPath,
                'relativePath' => 'track.mp3',
            ]]);

            $this->assertCount(1, $results);
            Process::assertRan(function (PendingProcess $process): bool {
                $command = $process->command;

                return is_array($command)
                    && array_slice($command, 0, 2) === ['docker', 'run']
                    && in_array('--rm', $command, true)
                    && in_array('--gpus=all', $command, true)
                    && in_array(
                        '--env=SONOTHEQUE_AUDIO_ACCELERATOR=cuda',
                        $command,
                        true,
                    );
            });
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
