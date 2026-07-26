<?php

namespace App\Providers;

use App\Music\Artwork\AlbumArtworkManager;
use App\Music\Intelligence\AudioAnalyzer;
use App\Music\Intelligence\AudioBenchmarkAnalyzerFactory;
use App\Music\Intelligence\DockerAudioBenchmarkAnalyzerFactory;
use App\Music\Intelligence\EssentiaCliAudioAnalyzer;
use App\Music\Intelligence\EssentiaDockerAudioAnalyzer;
use App\Music\Intelligence\UnavailableAudioAnalyzer;
use App\Music\Metadata\Mp3TrackMetadataWriter;
use App\Music\Metadata\TrackMetadataWriter;
use App\Music\PlaybackStatistics\Mp3PlaybackStatisticsTagWriter;
use App\Music\PlaybackStatistics\PlaybackStatisticsTagWriter;
use App\Music\Scanning\AudioContentFingerprinter;
use App\Music\Scanning\AudioFileDiscoverer;
use App\Music\Scanning\AudioMetadataProbe;
use App\Music\Scanning\AudioMetadataReader;
use App\Music\Scanning\AudioStreamFingerprinter;
use App\Music\Scanning\FfprobeAudioMetadataProbe;
use App\Music\Scanning\GetId3MetadataReader;
use App\Music\Scanning\LibraryEntryRenamer;
use App\Music\Scanning\LibraryFolderBrowser;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AudioAnalyzer::class, function (): AudioAnalyzer {
            return match (config('sonotheque.audio_intelligence.driver')) {
                'essentia_cli' => new EssentiaCliAudioAnalyzer(
                    pythonBinary: (string) config('sonotheque.audio_intelligence.python_binary'),
                    workerScript: (string) config('sonotheque.audio_intelligence.worker_script'),
                    modelPath: (string) config('sonotheque.audio_intelligence.model_path'),
                    timeoutSeconds: (int) config('sonotheque.audio_intelligence.timeout_seconds'),
                ),
                'essentia_docker' => new EssentiaDockerAudioAnalyzer(
                    image: (string) config('sonotheque.audio_intelligence.docker_image'),
                    modelPath: (string) config('sonotheque.audio_intelligence.model_path'),
                    timeoutSeconds: (int) config('sonotheque.audio_intelligence.timeout_seconds'),
                    cpuLimit: (float) config('sonotheque.audio_intelligence.cpu_limit'),
                    memoryLimit: (string) config('sonotheque.audio_intelligence.memory_limit'),
                    preparationWorkers: (int) config(
                        'sonotheque.audio_intelligence.preparation_workers',
                    ),
                    accelerator: (string) config('sonotheque.audio_intelligence.accelerator'),
                    persistent: (bool) config('sonotheque.audio_intelligence.persistent'),
                    persistentContainerName: (string) config(
                        'sonotheque.audio_intelligence.persistent_container_name',
                    ),
                    persistentStartupTimeoutSeconds: (int) config(
                        'sonotheque.audio_intelligence.persistent_startup_timeout_seconds',
                    ),
                ),
                default => new UnavailableAudioAnalyzer(),
            };
        });
        $this->app->bind(
            AudioBenchmarkAnalyzerFactory::class,
            DockerAudioBenchmarkAnalyzerFactory::class,
        );

        $this->app->bind(AudioMetadataReader::class, GetId3MetadataReader::class);
        $this->app->bind(AudioMetadataProbe::class, FfprobeAudioMetadataProbe::class);
        $this->app->bind(AudioContentFingerprinter::class, AudioStreamFingerprinter::class);
        $this->app->bind(TrackMetadataWriter::class, Mp3TrackMetadataWriter::class);
        $this->app->bind(PlaybackStatisticsTagWriter::class, Mp3PlaybackStatisticsTagWriter::class);

        $this->app->when(AudioFileDiscoverer::class)
            ->needs('$extensions')
            ->giveConfig('sonotheque.audio_extensions');
        $this->app->when(LibraryFolderBrowser::class)
            ->needs('$extensions')
            ->giveConfig('sonotheque.audio_extensions');
        $this->app->when(LibraryEntryRenamer::class)
            ->needs('$extensions')
            ->giveConfig('sonotheque.audio_extensions');

        $this->app->when(FfprobeAudioMetadataProbe::class)
            ->needs('$binary')
            ->giveConfig('sonotheque.metadata_probe.ffprobe_binary');
        $this->app->when(FfprobeAudioMetadataProbe::class)
            ->needs('$timeoutSeconds')
            ->giveConfig('sonotheque.metadata_probe.timeout_seconds');
        $this->app->when(AudioStreamFingerprinter::class)
            ->needs('$ffmpegBinary')
            ->giveConfig('sonotheque.audio_fingerprint.ffmpeg_binary');
        $this->app->when(AudioStreamFingerprinter::class)
            ->needs('$timeoutSeconds')
            ->giveConfig('sonotheque.audio_fingerprint.timeout_seconds');

        foreach ([
            '$disk' => 'sonotheque.artwork.disk',
            '$thumbnailWidth' => 'sonotheque.artwork.thumbnail_width',
            '$thumbnailHeight' => 'sonotheque.artwork.thumbnail_height',
            '$thumbnailQuality' => 'sonotheque.artwork.thumbnail_quality',
            '$maxSourceBytes' => 'sonotheque.artwork.max_source_bytes',
            '$maxSourcePixels' => 'sonotheque.artwork.max_source_pixels',
        ] as $parameter => $configuration) {
            $this->app->when(AlbumArtworkManager::class)
                ->needs($parameter)
                ->giveConfig($configuration);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
