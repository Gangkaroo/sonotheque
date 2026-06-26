<?php

namespace App\Http\Controllers;

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

        $result = DB::transaction(function () use ($track, $validated, $listenedMs, $durationMs, $playedAt, $counted, $sessionKey): array {
            if ($counted && $sessionKey) {
                $existingEvent = TrackPlayEvent::query()
                    ->where('source', 'app')
                    ->where('session_key', $sessionKey)
                    ->where('counted', true)
                    ->first();

                if ($existingEvent) {
                    return [
                        'duplicate' => true,
                        'statistics' => $track->playStatistic()->first(),
                    ];
                }
            }

            TrackPlayEvent::create([
                'track_id' => $track->id,
                'media_file_id' => $track->media_file_id,
                'played_at' => $playedAt,
                'listened_ms' => $listenedMs,
                'duration_ms' => $durationMs,
                'counted' => $counted,
                'source' => 'app',
                'context' => $validated['context'] ?? null,
                'session_key' => $counted ? $sessionKey : null,
            ]);

            if (! $counted) {
                return [
                    'duplicate' => false,
                    'statistics' => $track->playStatistic()->first(),
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
            ];
        });

        return response()->json([
            'counted' => $counted,
            'duplicate' => $result['duplicate'],
            'statistics' => $this->statisticsPayload($result['statistics']),
        ], $counted ? ($result['duplicate'] ? 200 : 201) : 202);
    }

    private function isCountedPlay(int $listenedMs, ?int $durationMs): bool
    {
        $thresholdMs = max(0, (int) config('music-library.counted_play_threshold_seconds', 15)) * 1000;
        $requiredMs = $durationMs !== null && $durationMs <= $thresholdMs ? 0 : $thresholdMs;

        return $listenedMs >= $requiredMs;
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
