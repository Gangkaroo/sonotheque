<?php

namespace App\Music\Streaming;

use Illuminate\Support\Facades\Cache;

class TrackStreamActivity
{
    public function touch(int $trackId): void
    {
        $graceSeconds = $this->graceSeconds();
        $activeUntil = now()->addSeconds($graceSeconds)->getTimestamp();

        Cache::put($this->cacheKey($trackId), $activeUntil, $graceSeconds);
    }

    public function retryAfterSeconds(int $trackId): int
    {
        $activeUntil = Cache::get($this->cacheKey($trackId));
        if (! is_int($activeUntil)) {
            return 0;
        }

        return max(0, $activeUntil - now()->getTimestamp());
    }

    private function graceSeconds(): int
    {
        return max(1, (int) config('sonotheque.audio_stream_activity_grace_seconds', 300));
    }

    private function cacheKey(int $trackId): string
    {
        return "sonotheque:audio-stream:{$trackId}";
    }
}
