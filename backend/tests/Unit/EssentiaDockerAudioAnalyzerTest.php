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
}
