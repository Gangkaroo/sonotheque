<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Models\TrackPlayEvent;
use App\Music\LastFm\LastFmApiClient;
use App\Music\LastFm\LastFmApiException;
use App\Support\CatalogPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LastFmSettingsController extends Controller
{
    public function __construct(
        private readonly LastFmApiClient $lastFm,
        private readonly CatalogPayloads $payloads,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload(ApplicationSetting::current()));
    }

    public function deliveries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:pending,sent,ignored,failed'],
        ]);
        $summary = TrackPlayEvent::query()
            ->whereNotNull('lastfm_status')
            ->selectRaw('lastfm_status, count(*) as aggregate')
            ->groupBy('lastfm_status')
            ->pluck('aggregate', 'lastfm_status');
        $deliveries = TrackPlayEvent::query()
            ->select([
                'id',
                'track_id',
                'played_at',
                'lastfm_status',
                'lastfm_attempts',
                'lastfm_scrobbled_at',
                'lastfm_error',
                'lastfm_ignored_code',
            ])
            ->whereNotNull('lastfm_status')
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('lastfm_status', $validated['status']),
            )
            ->with(['track' => fn ($query) => $query
                ->select(['id', 'title', 'sort_title', 'duration_ms', 'track_number', 'disc_number', 'album_id'])
                ->with([
                    'album:id,title,original_release_year,artwork_id',
                    'album.personalMetadata',
                    'artists:id,name',
                    'playStatistic:track_id,play_count,first_played_at,last_played_at',
                ])])
            ->orderByDesc('played_at')
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json([
            ...$this->payloads->paginated($deliveries, fn (TrackPlayEvent $event) => [
                'id' => $event->id,
                'status' => $event->lastfm_status,
                'attempts' => $event->lastfm_attempts,
                'playedAt' => $event->played_at?->toJSON(),
                'scrobbledAt' => $event->lastfm_scrobbled_at?->toJSON(),
                'error' => $event->lastfm_error,
                'ignoredCode' => $event->lastfm_ignored_code,
                'track' => $event->track ? $this->payloads->trackSummary($event->track) : null,
            ]),
            'summary' => collect(['pending', 'sent', 'ignored', 'failed'])
                ->mapWithKeys(fn (string $status) => [$status => (int) ($summary[$status] ?? 0)]),
        ]);
    }

    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'apiKey' => ['required', 'string', 'size:32'],
            'apiSecret' => ['required', 'string', 'size:32'],
        ]);

        try {
            $token = $this->lastFm->requestToken($validated['apiKey'], $validated['apiSecret']);
        } catch (LastFmApiException $exception) {
            throw ValidationException::withMessages(['lastFm' => $exception->getMessage()]);
        }

        $settings = ApplicationSetting::current();
        $settings->update([
            'lastfm_scrobbling_enabled' => false,
            'lastfm_api_key' => $validated['apiKey'],
            'lastfm_api_secret' => $validated['apiSecret'],
            'lastfm_session_key' => null,
            'lastfm_username' => null,
            'lastfm_auth_token' => $token,
            'lastfm_auth_token_expires_at' => now()->addHour(),
        ]);

        return response()->json($this->payload($settings->refresh()));
    }

    public function complete(): JsonResponse
    {
        $settings = ApplicationSetting::current();

        if (! $settings->hasLastFmCredentials()
            || blank($settings->lastfm_auth_token)
            || $settings->lastfm_auth_token_expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'lastFm' => 'Start a new Last.fm authorization before completing the connection.',
            ]);
        }

        try {
            $session = $this->lastFm->requestSession(
                $settings->lastfm_api_key,
                $settings->lastfm_api_secret,
                $settings->lastfm_auth_token,
            );
        } catch (LastFmApiException $exception) {
            throw ValidationException::withMessages(['lastFm' => $exception->getMessage()]);
        }

        $settings->update([
            'lastfm_scrobbling_enabled' => true,
            'lastfm_session_key' => $session['key'],
            'lastfm_username' => $session['name'],
            'lastfm_auth_token' => null,
            'lastfm_auth_token_expires_at' => null,
        ]);

        return response()->json($this->payload($settings->refresh()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        $settings = ApplicationSetting::current();

        if ($validated['enabled'] && ! $settings->hasLastFmSession()) {
            throw ValidationException::withMessages([
                'enabled' => 'Connect a Last.fm account before enabling scrobbling.',
            ]);
        }

        $settings->update(['lastfm_scrobbling_enabled' => $validated['enabled']]);

        return response()->json($this->payload($settings));
    }

    public function disconnect(): JsonResponse
    {
        $settings = ApplicationSetting::current();
        $settings->update([
            'lastfm_scrobbling_enabled' => false,
            'lastfm_session_key' => null,
            'lastfm_username' => null,
            'lastfm_auth_token' => null,
            'lastfm_auth_token_expires_at' => null,
        ]);

        return response()->json($this->payload($settings->refresh()));
    }

    /**
     * @return array{
     *     configured: bool,
     *     connected: bool,
     *     enabled: bool,
     *     username: ?string,
     *     apiKey: ?string,
     *     authorizationPending: bool,
     *     authorizationExpiresAt: ?string,
     *     authorizationUrl: ?string
     * }
     */
    private function payload(ApplicationSetting $settings): array
    {
        $authorizationPending = filled($settings->lastfm_auth_token)
            && $settings->lastfm_auth_token_expires_at?->isFuture();

        return [
            'configured' => $settings->hasLastFmCredentials(),
            'connected' => $settings->hasLastFmSession(),
            'enabled' => $settings->scrobblesToLastFm(),
            'username' => $settings->lastfm_username,
            'apiKey' => $settings->lastfm_api_key,
            'authorizationPending' => (bool) $authorizationPending,
            'authorizationExpiresAt' => $authorizationPending
                ? $settings->lastfm_auth_token_expires_at?->toJSON()
                : null,
            'authorizationUrl' => $authorizationPending
                ? $this->lastFm->authorizationUrl($settings->lastfm_api_key, $settings->lastfm_auth_token)
                : null,
        ];
    }
}
