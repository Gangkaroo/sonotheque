<?php

namespace Tests\Fakes;

use App\Music\Intelligence\AnalyzerProfile;
use App\Music\Intelligence\AudioAnalyzer;
use App\Music\Intelligence\AudioAnalyzerHealth;
use App\Music\Intelligence\AudioAnalyzerResult;

class FakeAudioAnalyzer implements AudioAnalyzer
{
    /** @var list<AudioAnalyzerResult> */
    public array $results = [];

    /** @var list<list<array<string, mixed>>> */
    public array $requests = [];

    public int $shutdownCalls = 0;

    public int $healthCalls = 0;

    public function __construct(
        public AudioAnalyzerHealth $health,
    ) {
    }

    public static function ready(): self
    {
        return new self(new AudioAnalyzerHealth(
            status: 'ready',
            message: 'Ready for testing.',
            profile: new AnalyzerProfile(
                key: 'test-analyzer',
                protocolVersion: 1,
                analyzerName: 'Test analyzer',
                analyzerVersion: '1.0.0',
                analyzerLicense: 'Test license',
                modelName: 'Test model',
                modelVersion: '1',
                modelChecksum: str_repeat('a', 64),
                modelLicense: 'Test model license',
                embeddingDimensions: 3,
                sampleRate: 16000,
                manifest: ['windowStrategy' => 'test'],
            ),
        ));
    }

    public function health(): AudioAnalyzerHealth
    {
        $this->healthCalls++;

        return $this->health;
    }

    public function analyzeBatch(array $requests): array
    {
        $this->requests[] = $requests;

        return $this->results;
    }

    public function shutdown(): void
    {
        $this->shutdownCalls++;
    }
}
