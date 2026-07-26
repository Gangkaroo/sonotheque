<?php

namespace Tests\Feature;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\AudioAnalyzerBenchmark;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use App\Music\Intelligence\AnalyzerProfile;
use App\Music\Intelligence\AudioAnalyzer;
use App\Music\Intelligence\AudioAnalyzerBenchmarkRunner;
use App\Music\Intelligence\AudioAnalyzerHealth;
use App\Music\Intelligence\AudioAnalyzerResult;
use App\Music\Intelligence\AudioBenchmarkAnalyzerFactory;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RunAudioAnalyzerBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private string $libraryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->libraryPath = storage_path('framework/testing/audio-benchmark-'.uniqid());
        File::ensureDirectoryExists($this->libraryPath);
        $this->createCatalog();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->libraryPath);
        parent::tearDown();
    }

    public function test_it_adaptively_benchmarks_the_fastest_verified_configuration(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });
        $benchmark = AudioAnalyzerBenchmark::create([
            'status' => 'queued',
            'sample_size' => 15,
            'results' => [],
            'total_configuration_count' => 6,
        ]);
        $factory = new FakeBenchmarkAnalyzerFactory();
        $runner = new AudioAnalyzerBenchmarkRunner($factory, new LibraryPathGuard());

        $runner->run($benchmark);

        $benchmark->refresh();
        $this->assertSame(
            'completed',
            $benchmark->status,
            json_encode($benchmark->results, JSON_PRETTY_PRINT),
        );
        $this->assertCount(6, $benchmark->results);
        $this->assertCount(15, $benchmark->sample_track_ids);
        $this->assertSame('cuda', $benchmark->recommendation['accelerator']);
        $this->assertSame(2, $benchmark->recommendation['preparationWorkers']);
        $this->assertSame(15, $benchmark->recommendation['chunkSize']);
        $this->assertTrue(collect($benchmark->results)->every(
            fn (array $result): bool => $result['equivalent'] === true,
        ));
        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'random()'),
        ));
    }

    public function test_missing_cuda_is_reported_without_breaking_cpu_benchmarking(): void
    {
        $benchmark = AudioAnalyzerBenchmark::create([
            'status' => 'queued',
            'sample_size' => 15,
            'results' => [],
            'total_configuration_count' => 6,
        ]);
        $runner = new AudioAnalyzerBenchmarkRunner(
            new FakeBenchmarkAnalyzerFactory(cudaAvailable: false),
            new LibraryPathGuard(),
        );

        $runner->run($benchmark);

        $benchmark->refresh();
        $this->assertSame('partial', $benchmark->status);
        $this->assertSame(
            'cpu',
            $benchmark->recommendation['accelerator'] ?? null,
            json_encode($benchmark->results, JSON_PRETTY_PRINT),
        );
        $this->assertCount(
            3,
            collect($benchmark->results)->where('status', 'unavailable'),
        );
        $this->assertCount(
            3,
            collect($benchmark->results)->where('status', 'completed'),
        );
    }

    private function createCatalog(): void
    {
        $library = Library::create(['name' => 'Benchmark']);
        $root = $library->roots()->create([
            'name' => 'Root',
            'path' => $this->libraryPath,
            'path_hash' => hash('sha256', mb_strtolower($this->libraryPath)),
            'enabled' => true,
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'title' => 'Benchmark album',
            'sort_title' => 'Benchmark album',
            'relative_path' => 'Album',
            'relative_path_hash' => hash('sha256', 'album'),
        ]);
        File::ensureDirectoryExists($this->libraryPath.'/Album');

        foreach (range(1, 15) as $position) {
            $relativePath = "Album/{$position}.mp3";
            File::put($this->libraryPath.'/'.$relativePath, "audio-{$position}");
            $mediaFile = MediaFile::create([
                'library_root_id' => $root->id,
                'album_id' => $album->id,
                'relative_path' => $relativePath,
                'relative_path_hash' => hash('sha256', $relativePath),
                'file_size' => 100,
                'modified_at' => now(),
                'status' => MediaFileStatus::Available,
                'last_seen_at' => now(),
            ]);
            Track::create([
                'album_id' => $album->id,
                'media_file_id' => $mediaFile->id,
                'title' => "Track {$position}",
                'sort_title' => "Track {$position}",
                'duration_ms' => 180_000,
            ]);
        }
    }
}

class FakeBenchmarkAnalyzerFactory implements AudioBenchmarkAnalyzerFactory
{
    public function __construct(private readonly bool $cudaAvailable = true)
    {
    }

    public function create(
        int $benchmarkId,
        string $accelerator,
        int $preparationWorkers,
        int $chunkSize,
    ): AudioAnalyzer {
        $cudaAvailable = $this->cudaAvailable;

        return new class ($accelerator, $preparationWorkers, $cudaAvailable) implements AudioAnalyzer {
            public function __construct(
                private readonly string $accelerator,
                private readonly int $preparationWorkers,
                private readonly bool $cudaAvailable,
            ) {
            }

            public function health(): AudioAnalyzerHealth
            {
                if ($this->accelerator === 'cuda' && ! $this->cudaAvailable) {
                    return new AudioAnalyzerHealth(
                        status: 'error',
                        message: 'CUDA is unavailable.',
                    );
                }

                return new AudioAnalyzerHealth(
                    status: 'ready',
                    message: 'Ready.',
                    profile: new AnalyzerProfile(
                        key: 'benchmark-test',
                        protocolVersion: 1,
                        analyzerName: 'Test analyzer',
                        analyzerVersion: '1',
                        analyzerLicense: 'Test license',
                        modelName: 'Test model',
                        modelVersion: '1',
                        modelChecksum: str_repeat('a', 64),
                        modelLicense: 'Test model license',
                        embeddingDimensions: 3,
                        sampleRate: 16000,
                        manifest: [],
                    ),
                );
            }

            public function analyzeBatch(array $requests): array
            {
                $milliseconds = $this->accelerator === 'cpu'
                    ? 100
                    : match ($this->preparationWorkers) {
                        2 => 10,
                        3 => 50,
                        default => 70,
                    };
                usleep($milliseconds * 1000);

                return array_map(
                    fn (array $request): AudioAnalyzerResult => new AudioAnalyzerResult(
                        itemId: $request['itemId'],
                        status: 'completed',
                        features: ['bpm' => 120.0],
                        embedding: [0.1, 0.2, 0.3],
                        runtimeMs: $milliseconds,
                        windowsAnalyzed: 1,
                        timings: [
                            'decodeMs' => 1,
                            'featureExtractionMs' => 2,
                            'embeddingMs' => 3,
                        ],
                        hardware: ['accelerator' => $this->accelerator],
                    ),
                    $requests,
                );
            }

            public function shutdown(): void
            {
            }
        };
    }
}
