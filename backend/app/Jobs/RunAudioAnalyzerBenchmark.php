<?php

namespace App\Jobs;

use App\Models\AudioAnalyzerBenchmark;
use App\Music\Intelligence\AudioAnalyzerBenchmarkRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunAudioAnalyzerBenchmark implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly int $audioAnalyzerBenchmarkId)
    {
        $this->onQueue('analysis');
    }

    public function handle(AudioAnalyzerBenchmarkRunner $runner): void
    {
        $benchmark = AudioAnalyzerBenchmark::findOrFail($this->audioAnalyzerBenchmarkId);
        if ($benchmark->status !== 'queued') {
            return;
        }

        $runner->run($benchmark);
    }

    public function failed(?Throwable $exception): void
    {
        $benchmark = AudioAnalyzerBenchmark::find($this->audioAnalyzerBenchmarkId);
        if ($benchmark === null || in_array(
            $benchmark->status,
            ['completed', 'partial', 'failed', 'cancelled'],
            true,
        )) {
            return;
        }

        $benchmark->update([
            'status' => 'failed',
            'error' => mb_substr(
                $exception?->getMessage() ?? 'The analyzer benchmark failed.',
                0,
                4000,
            ),
            'finished_at' => now(),
        ]);
    }
}
