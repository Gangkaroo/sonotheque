<?php

namespace App\Music\Intelligence;

interface AudioBenchmarkAnalyzerFactory
{
    public function create(
        int $benchmarkId,
        string $accelerator,
        int $preparationWorkers,
        int $chunkSize,
    ): AudioAnalyzer;
}
