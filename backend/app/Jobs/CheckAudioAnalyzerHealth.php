<?php

namespace App\Jobs;

use App\Music\Intelligence\AudioAnalyzer;
use App\Music\Intelligence\AudioAnalyzerHealth;
use App\Music\Intelligence\AudioAnalyzerHealthProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CheckAudioAnalyzerHealth implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public function __construct(public readonly string $requestId)
    {
        $this->onQueue('analysis-control');
    }

    public function handle(AudioAnalyzer $analyzer): void
    {
        try {
            $health = $analyzer->health();
        } catch (Throwable $exception) {
            $health = new AudioAnalyzerHealth(
                status: 'error',
                message: $exception->getMessage(),
            );
        }

        Cache::put(
            AudioAnalyzerHealthProbe::responseCacheKey($this->requestId),
            $health->toArray(),
            now()->addMinutes(5),
        );
    }
}
