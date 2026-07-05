<?php

namespace App\Music\Enrichment;

use App\Models\ApplicationSetting;
use App\Music\Enrichment\Providers\LastFmInformationProvider;
use App\Music\Enrichment\Providers\LrclibLyricsProvider;
use App\Music\Enrichment\Providers\MusicBrainzInformationProvider;
use InvalidArgumentException;

class OnlineEnrichmentDiagnostics
{
    public function __construct(
        private readonly ProviderRequestGate $requestGate,
        private readonly LastFmInformationProvider $informationProvider,
        private readonly LrclibLyricsProvider $lyricsProvider,
        private readonly MusicBrainzInformationProvider $musicBrainzProvider,
    ) {
    }

    /** @return array{provider: string, status: string, errorCode: string|null} */
    public function test(string $provider): array
    {
        if (! in_array($provider, ['lastfm', 'lrclib', 'musicbrainz'], true)) {
            throw new InvalidArgumentException('Unsupported online enrichment provider.');
        }

        if ($provider === 'lastfm' && blank(ApplicationSetting::current()->lastfm_api_key)) {
            return $this->result($provider, 'not_configured');
        }

        try {
            $this->requestGate->run($provider, function () use ($provider): void {
                if ($provider === 'lastfm') {
                    $this->informationProvider->testConnection();

                    return;
                }

                if ($provider === 'lrclib') {
                    $this->lyricsProvider->testConnection();

                    return;
                }

                $this->musicBrainzProvider->testConnection();
            });
        } catch (EnrichmentProviderException $exception) {
            return $this->result($provider, 'error', $exception->errorCode);
        }

        return $this->result($provider, 'available');
    }

    /** @return array{provider: string, status: string, errorCode: string|null} */
    private function result(string $provider, string $status, ?string $errorCode = null): array
    {
        return [
            'provider' => $provider,
            'status' => $status,
            'errorCode' => $errorCode,
        ];
    }
}
