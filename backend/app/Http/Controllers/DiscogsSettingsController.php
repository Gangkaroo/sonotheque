<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Music\Discogs\DiscogsApiClient;
use App\Music\Discogs\DiscogsApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DiscogsSettingsController extends Controller
{
    public function __construct(private readonly DiscogsApiClient $discogs)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload(ApplicationSetting::current()));
    }

    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'personalAccessToken' => ['required', 'string', 'max:255'],
        ]);
        $token = trim($validated['personalAccessToken']);

        try {
            $identity = $this->discogs->identity($token);
        } catch (DiscogsApiException $exception) {
            throw ValidationException::withMessages(['discogs' => $exception->getMessage()]);
        }

        $settings = ApplicationSetting::current();
        $settings->update([
            'discogs_personal_access_token' => $token,
            'discogs_username' => $identity['username'],
            'discogs_user_id' => $identity['id'],
            'discogs_connected_at' => now(),
        ]);

        return response()->json($this->payload($settings->refresh()));
    }

    public function disconnect(): JsonResponse
    {
        $settings = ApplicationSetting::current();
        $settings->update([
            'discogs_personal_access_token' => null,
            'discogs_username' => null,
            'discogs_user_id' => null,
            'discogs_connected_at' => null,
        ]);

        return response()->json($this->payload($settings->refresh()));
    }

    /** @return array{connected: bool, username: ?string, userId: ?int, connectedAt: ?string} */
    private function payload(ApplicationSetting $settings): array
    {
        return [
            'connected' => $settings->hasDiscogsConnection(),
            'username' => $settings->discogs_username,
            'userId' => $settings->discogs_user_id,
            'connectedAt' => $settings->discogs_connected_at?->toJSON(),
        ];
    }
}
