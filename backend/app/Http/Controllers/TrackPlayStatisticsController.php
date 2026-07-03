<?php

namespace App\Http\Controllers;

use App\Jobs\SynchronizeTrackPlaybackStatistics;
use App\Jobs\ScrobbleTrackPlayEvent;
use App\Models\ApplicationSetting;
use App\Models\Track;
use App\Models\TrackPlayEvent;
use App\Models\TrackPlayStatistic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TrackPlayStatisticsController extends Controller
{
    public function store(Request $request, Track $track): JsonResponse
    {
        $validated = $request->validate([
            'listenedMs' => ['required', 'integer', 'min:0'],
            'durationMs' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'playedAt' => ['sometimes', 'nullable', 'date'],
            'context' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sessionKey' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $listenedMs = (int) $validated['listenedMs'];
        $durationMs = $track->duration_ms ?? ($validated['durationMs'] ?? null);
        $playedAt = isset($validated['playedAt']) ? Carbon::parse($validated['playedAt']) : now();
        $counted = $this->isCountedPlay($listenedMs, $durationMs);
        $sessionKey = $validated['sessionKey'] ?? null;
        $settings = ApplicationSetting::current();

        $result = DB::transaction(function () use (
            $track,
            $validated,
            $listenedMs,
            $durationMs,
            $playedAt,
            $counted,
            $sessionKey,
            $settings,
        ): array {
            if ($counted && $sessionKey) {
                $existingEvent = TrackPlayEvent::query()
                    ->where('track_id', $track->id)
                    ->where('source', 'app')
                    ->where('session_key', $sessionKey)
                    ->where('counted', true)
                    ->first();

                if ($existingEvent) {
                    $lastFmQueued = $settings->scrobblesToLastFm()
                        && $existingEvent->lastfm_status === null;
                    $existingEvent->update([
                        'listened_ms' => max($existingEvent->listened_ms, $listenedMs),
                        'duration_ms' => $durationMs ?? $existingEvent->duration_ms,
                        'lastfm_status' => $lastFmQueued ? 'pending' : $existingEvent->lastfm_status,
                    ]);

                    return [
                        'duplicate' => true,
                        'statistics' => $track->playStatistic()->first(),
                        'playEventId' => $existingEvent->id,
                        'lastFmQueued' => $lastFmQueued,
                    ];
                }
            }

            $lastFmQueued = $counted && $settings->scrobblesToLastFm();
            $event = TrackPlayEvent::create([
                'track_id' => $track->id,
                'media_file_id' => $track->media_file_id,
                'played_at' => $playedAt,
                'listened_ms' => $listenedMs,
                'duration_ms' => $durationMs,
                'counted' => $counted,
                'source' => 'app',
                'context' => $validated['context'] ?? null,
                'session_key' => $counted ? $sessionKey : null,
                'lastfm_status' => $lastFmQueued ? 'pending' : null,
            ]);

            if (! $counted) {
                return [
                    'duplicate' => false,
                    'statistics' => $track->playStatistic()->first(),
                    'playEventId' => $event->id,
                    'lastFmQueued' => false,
                ];
            }

            $statistics = TrackPlayStatistic::firstOrNew(['track_id' => $track->id]);
            $statistics->play_count = ($statistics->play_count ?? 0) + 1;
            if (! $statistics->first_played_at || $playedAt->lt($statistics->first_played_at)) {
                $statistics->first_played_at = $playedAt;
            }
            if (! $statistics->last_played_at || $playedAt->gt($statistics->last_played_at)) {
                $statistics->last_played_at = $playedAt;
            }
            $statistics->save();

            return [
                'duplicate' => false,
                'statistics' => $statistics,
                'playEventId' => $event->id,
                'lastFmQueued' => $lastFmQueued,
            ];
        });

        if ($counted
            && ! $result['duplicate']
            && $settings->synchronizesPlaybackStatisticsWithTags()) {
            SynchronizeTrackPlaybackStatistics::dispatch($track->id)
                ->delay(now()->addSeconds($this->playbackStatisticsSyncDelaySeconds(
                    $listenedMs,
                    $durationMs,
                )))
                ->afterCommit();
        }

        if ($result['lastFmQueued']) {
            ScrobbleTrackPlayEvent::dispatch($result['playEventId'])->afterCommit();
        }

        return response()->json([
            'counted' => $counted,
            'duplicate' => $result['duplicate'],
            'lastFmQueued' => $result['lastFmQueued'],
            'statistics' => $this->statisticsPayload($result['statistics']),
        ], $counted ? ($result['duplicate'] ? 200 : 201) : 202);
    }

    private function isCountedPlay(int $listenedMs, ?int $durationMs): bool
    {
        $minimumDurationMs = max(
            0,
            (int) config('music-library.counted_play_minimum_track_seconds', 30) * 1000,
        );

        if ($durationMs === null || $durationMs <= $minimumDurationMs) {
            return false;
        }

        $maximumThresholdMs = max(
            0,
            (int) config('music-library.counted_play_maximum_threshold_seconds', 240) * 1000,
        );
        $requiredMs = min((int) ceil($durationMs / 2), $maximumThresholdMs);

        return $listenedMs >= $requiredMs;
    }

    private function playbackStatisticsSyncDelaySeconds(int $listenedMs, ?int $durationMs): int
    {
        $idleDelaySeconds = max(
            0,
            (int) config('music-library.play_statistics_sync_delay_seconds', 30),
        );
        $remainingPlaybackMs = max(0, ($durationMs ?? $listenedMs) - $listenedMs);

        return $idleDelaySeconds + (int) ceil($remainingPlaybackMs / 1000);
    }

    /** @return array{playCount: int, firstPlayedAt: ?string, lastPlayedAt: ?string} */
    private function statisticsPayload(?TrackPlayStatistic $statistics): array
    {
        return [
            'playCount' => $statistics?->play_count ?? 0,
            'firstPlayedAt' => $statistics?->first_played_at?->toJSON(),
            'lastPlayedAt' => $statistics?->last_played_at?->toJSON(),
        ];
    }
}
