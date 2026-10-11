<?php

namespace Tests\Unit;

use App\Music\Intelligence\AudioAnalyzer;
use App\Music\Intelligence\AudioAnalyzerHealth;
use App\Music\Intelligence\AudioAnalyzerHealthProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AudioAnalyzerHealthProbeTest extends TestCase
{
    #[DataProvider('runtimeBudgets')]
    public function test_health_check_has_a_bounded_runtime_allowance_and_restores_it(
        bool $queued,
        int $originalLimit,
        int $expectedLimit,
    ): void {
        config([
            'sonotheque.audio_intelligence.driver' => 'essentia_docker',
            'sonotheque.audio_intelligence.health_via_queue' => $queued,
            'sonotheque.audio_intelligence.persistent_startup_timeout_seconds' => 180,
            'sonotheque.audio_intelligence.health_queue_timeout_seconds' => 210,
            'queue.default' => 'sync',
        ]);
        $analyzer = $this->createMock(AudioAnalyzer::class);
        $analyzer->expects($this->once())->method('health')->willReturnCallback(
            function () use ($expectedLimit): AudioAnalyzerHealth {
                $this->assertSame($expectedLimit, (int) ini_get('max_execution_time'));

                return new AudioAnalyzerHealth(status: 'error', message: 'Test GPU unavailable.');
            },
        );
        $this->app->instance(AudioAnalyzer::class, $analyzer);
        $previousLimit = (int) ini_get('max_execution_time');

        try {
            set_time_limit($originalLimit);
            $health = app(AudioAnalyzerHealthProbe::class)->check();

            $this->assertSame('error', $health->status);
            $this->assertSame('Test GPU unavailable.', $health->message);
            $this->assertSame($originalLimit, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit($previousLimit);
        }
    }

    public static function runtimeBudgets(): array
    {
        return [
            'direct Docker startup' => [false, 30, 210],
            'packaged worker startup' => [true, 30, 240],
            'unlimited CLI runtime' => [false, 0, 0],
            'existing longer limit' => [false, 300, 300],
        ];
    }

    public function test_analyzer_exception_also_restores_the_request_limit(): void
    {
        config(['sonotheque.audio_intelligence.health_via_queue' => false]);
        $analyzer = $this->createMock(AudioAnalyzer::class);
        $analyzer->method('health')->willThrowException(new RuntimeException('GPU startup failed.'));
        $probe = new AudioAnalyzerHealthProbe($analyzer);
        $previousLimit = (int) ini_get('max_execution_time');

        try {
            set_time_limit(30);
            try {
                $probe->check();
                $this->fail('The analyzer failure was not propagated.');
            } catch (RuntimeException $exception) {
                $this->assertSame('GPU startup failed.', $exception->getMessage());
            }

            $this->assertSame(30, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit($previousLimit);
        }
    }
}
