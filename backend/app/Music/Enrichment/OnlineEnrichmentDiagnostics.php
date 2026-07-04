<?php

namespace App\Music\Enrichment;

use App\Models\ApplicationSetting;
use App\Music\Enrichment\Providers\LastFmInformationProvider;
use App\Music\Enrichment\Providers\LrclibLyricsProvider;
use InvalidArgumentException;

class OnlineEnrichmentDiagnostics
{
    public function __construct(
        private readonly ProviderRequestGate $requestGate,
        private readonly LastFmInformationProvider $informationProvider,
        private readonly LrclibLyricsProvider $lyricsProvider,
    ) {
    }

    /** @return array{provider: string, status: string, errorCode: string|null} */
    public function test(string $provider): array
    {
        if (! in_array($provider, ['lastfm', 'lrclib'], true)) {
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

                $this->lyricsProvider->testConnection();
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
