<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Music\LastFm\LastFmApiClient;
use App\Music\LastFm\LastFmApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LastFmSettingsController extends Controller
{
    public function __construct(private readonly LastFmApiClient $lastFm)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload(ApplicationSetting::current()));
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
