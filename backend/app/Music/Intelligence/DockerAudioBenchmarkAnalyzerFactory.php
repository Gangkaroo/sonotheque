<?php

namespace App\Music\Intelligence;

class DockerAudioBenchmarkAnalyzerFactory implements AudioBenchmarkAnalyzerFactory
{
    public function create(
        int $benchmarkId,
        string $accelerator,
        int $preparationWorkers,
        int $chunkSize,
    ): AudioAnalyzer {
        $image = $accelerator === 'cuda'
            ? (string) config('sonotheque.audio_intelligence.benchmark_cuda_image')
            : (string) config('sonotheque.audio_intelligence.benchmark_cpu_image');

        return new EssentiaDockerAudioAnalyzer(
            image: $image,
            modelPath: (string) config('sonotheque.audio_intelligence.model_path'),
            timeoutSeconds: (int) config('sonotheque.audio_intelligence.timeout_seconds'),
            cpuLimit: (float) config('sonotheque.audio_intelligence.cpu_limit'),
            memoryLimit: (string) config('sonotheque.audio_intelligence.memory_limit'),
            preparationWorkers: $preparationWorkers,
            accelerator: $accelerator,
            persistent: true,
            persistentContainerName: implode('-', [
                'sonotheque-audio-benchmark',
                $benchmarkId,
                $accelerator,
                $preparationWorkers,
                $chunkSize,
            ]),
            persistentStartupTimeoutSeconds: (int) config(
                'sonotheque.audio_intelligence.persistent_startup_timeout_seconds',
            ),
        );
    }
}
