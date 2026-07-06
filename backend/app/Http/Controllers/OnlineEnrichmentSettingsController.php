<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Music\Enrichment\ArtistImageCache;
use App\Music\Enrichment\OnlineContentCacheRepository;
use App\Music\Enrichment\OnlineEnrichmentDiagnostics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnlineEnrichmentSettingsController extends Controller
{
    public function __construct(
        private readonly OnlineContentCacheRepository $cache,
        private readonly OnlineEnrichmentDiagnostics $diagnostics,
        private readonly ArtistImageCache $images,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload(ApplicationSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'informationEnabled' => ['required', 'boolean'],
            'lyricsEnabled' => ['required', 'boolean'],
        ]);
        $settings = ApplicationSetting::current();
        $settings->update([
            'online_information_enabled' => $validated['informationEnabled'],
            'online_lyrics_enabled' => $validated['lyricsEnabled'],
        ]);

        return response()->json($this->payload($settings));
    }

    public function clearCache(): JsonResponse
    {
        $deleted = $this->cache->clear();
        $this->images->clear();

        return response()->json([
            'deleted' => $deleted,
            'cache' => $this->cache->summary(),
        ]);
    }

    public function testProvider(string $provider): JsonResponse
    {
        abort_unless(in_array($provider, ['lastfm', 'lrclib', 'musicbrainz'], true), 404);

        return response()->json($this->diagnostics->test($provider));
    }

    /** @return array<string, mixed> */
    private function payload(ApplicationSetting $settings): array
    {
        return [
            'informationEnabled' => $settings->online_information_enabled,
            'lyricsEnabled' => $settings->online_lyrics_enabled,
            'cache' => $this->cache->summary(),
        ];
    }
}
