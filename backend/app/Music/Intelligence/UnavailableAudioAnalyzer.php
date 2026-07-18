<?php

namespace App\Music\Intelligence;

use RuntimeException;

class UnavailableAudioAnalyzer implements AudioAnalyzer
{
    public function health(): AudioAnalyzerHealth
    {
        return new AudioAnalyzerHealth(
            status: 'not_configured',
            message: 'No local audio analyzer driver is configured.',
        );
    }

    public function analyzeBatch(array $requests): array
    {
        throw new RuntimeException('No local audio analyzer driver is configured.');
    }
}
