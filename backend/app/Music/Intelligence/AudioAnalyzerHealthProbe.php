<?php

namespace App\Music\Intelligence;

use App\Jobs\CheckAudioAnalyzerHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AudioAnalyzerHealthProbe
{
    public function __construct(private readonly AudioAnalyzer $analyzer)
    {
    }

    public function check(): AudioAnalyzerHealth
    {
        $previousLimit = (int) ini_get('max_execution_time');
        $queued = config('sonotheque.audio_intelligence.health_via_queue')
            && config('sonotheque.audio_intelligence.driver') !== 'none';
        $timeout = $queued
            ? max(10, (int) config('sonotheque.audio_intelligence.health_queue_timeout_seconds', 210))
            : max(60, (int) config('sonotheque.audio_intelligence.persistent_startup_timeout_seconds', 180));
        $extended = $previousLimit > 0 && $previousLimit < $timeout + 30;

        // On Windows, PHP's request limit also counts time waiting for Docker.
        if ($extended) {
            set_time_limit($timeout + 30);
        }

        try {
            return $this->checkAnalyzer();
        } finally {
            if ($extended) {
                set_time_limit($previousLimit);
            }
        }
    }

    private function checkAnalyzer(): AudioAnalyzerHealth
    {
        if (! config('sonotheque.audio_intelligence.health_via_queue')
            || config('sonotheque.audio_intelligence.driver') === 'none') {
            return $this->analyzer->health();
        }

        $requestId = (string) Str::uuid();
        CheckAudioAnalyzerHealth::dispatch($requestId);
        $deadline = microtime(true) + max(
            10,
            (int) config('sonotheque.audio_intelligence.health_queue_timeout_seconds', 210),
        );

        do {
            $payload = Cache::pull(self::responseCacheKey($requestId));
            if (is_array($payload)) {
                return $this->fromArray($payload);
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        return new AudioAnalyzerHealth(
            status: 'error',
            message: 'The packaged analysis worker did not answer the analyzer check in time.',
        );
    }

    public static function responseCacheKey(string $requestId): string
    {
        return 'sonotheque:audio-intelligence:health-request:'.$requestId;
    }

    /** @param array<string, mixed> $payload */
    private function fromArray(array $payload): AudioAnalyzerHealth
    {
        $profile = is_array($payload['profile'] ?? null)
            ? AnalyzerProfile::fromArray($payload['profile'])
            : null;

        return new AudioAnalyzerHealth(
            status: is_string($payload['status'] ?? null) ? $payload['status'] : 'error',
            message: is_string($payload['message'] ?? null) ? $payload['message'] : null,
            profile: $profile,
        );
    }
}
