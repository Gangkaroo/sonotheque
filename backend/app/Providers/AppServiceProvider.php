<?php

namespace App\Providers;

use App\Music\Artwork\AlbumArtworkManager;
use App\Music\PlaybackStatistics\Mp3PlaybackStatisticsTagWriter;
use App\Music\PlaybackStatistics\PlaybackStatisticsTagWriter;
use App\Music\Scanning\AudioFileDiscoverer;
use App\Music\Scanning\AudioMetadataReader;
use App\Music\Scanning\GetId3MetadataReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AudioMetadataReader::class, GetId3MetadataReader::class);
        $this->app->bind(PlaybackStatisticsTagWriter::class, Mp3PlaybackStatisticsTagWriter::class);

        $this->app->when(AudioFileDiscoverer::class)
            ->needs('$extensions')
            ->giveConfig('music-library.audio_extensions');

        foreach ([
            '$disk' => 'music-library.artwork.disk',
            '$thumbnailWidth' => 'music-library.artwork.thumbnail_width',
            '$thumbnailHeight' => 'music-library.artwork.thumbnail_height',
            '$thumbnailQuality' => 'music-library.artwork.thumbnail_quality',
            '$maxSourceBytes' => 'music-library.artwork.max_source_bytes',
            '$maxSourcePixels' => 'music-library.artwork.max_source_pixels',
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
