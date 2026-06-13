<?php

namespace App\Providers;

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

        $this->app->when(AudioFileDiscoverer::class)
            ->needs('$extensions')
            ->giveConfig('music-library.audio_extensions');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
