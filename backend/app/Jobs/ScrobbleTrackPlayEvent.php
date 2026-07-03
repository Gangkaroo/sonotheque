<?php

namespace App\Jobs;

use App\Models\ApplicationSetting;
use App\Models\TrackPlayEvent;
use App\Music\LastFm\LastFmApiClient;
use App\Music\LastFm\LastFmApiException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ScrobbleTrackPlayEvent implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $playEventId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->playEventId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(LastFmApiClient $lastFm): void
    {
        $event = TrackPlayEvent::query()
            ->with(['track.album.primaryArtist', 'track.artists'])
            ->find($this->playEventId);

        if ($event === null || in_array($event->lastfm_status, ['sent', 'ignored'], true)) {
            return;
        }

        $settings = ApplicationSetting::current();
        if (! $settings->scrobblesToLastFm()) {
            $event->update([
                'lastfm_status' => 'failed',
                'lastfm_error' => 'Last.fm scrobbling is not connected or enabled.',
            ]);

            return;
        }

        $track = $event->track;
        $artist = $track?->artists->sortBy('pivot.position')->first();

        if ($track === null || $artist === null) {
            $event->update([
                'lastfm_status' => 'failed',
                'lastfm_error' => 'The track has no artist to submit to Last.fm.',
            ]);

            return;
        }

        $event->increment('lastfm_attempts');

        try {
            $result = $lastFm->scrobble(
                $settings->lastfm_api_key,
                $settings->lastfm_api_secret,
                $settings->lastfm_session_key,
                array_filter([
                    'artist' => $artist->name,
                    'track' => $track->title,
                    'timestamp' => $event->played_at->getTimestamp(),
                    'album' => $track->album?->title,
                    'albumArtist' => $track->album?->primaryArtist?->name,
                    'duration' => $event->duration_ms !== null
                        ? max(1, (int) round($event->duration_ms / 1000))
                        : null,
                    'trackNumber' => $track->track_number,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            );
        } catch (LastFmApiException $exception) {
            $event->update([
                'lastfm_status' => $exception->retriable ? 'pending' : 'failed',
                'lastfm_error' => $exception->getMessage(),
            ]);

            if ($exception->apiCode === 9) {
                $settings->update(['lastfm_scrobbling_enabled' => false]);
            }

            if ($exception->retriable) {
                throw $exception;
            }

            return;
        }

        $event->update($result->accepted ? [
            'lastfm_status' => 'sent',
            'lastfm_scrobbled_at' => now(),
            'lastfm_error' => null,
            'lastfm_ignored_code' => null,
        ] : [
            'lastfm_status' => 'ignored',
            'lastfm_error' => $result->message ?: 'Last.fm ignored this scrobble.',
            'lastfm_ignored_code' => $result->ignoredCode,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        TrackPlayEvent::query()->whereKey($this->playEventId)->update([
            'lastfm_status' => 'failed',
            'lastfm_error' => $exception?->getMessage() ?? 'Last.fm scrobbling failed.',
        ]);
    }
}
