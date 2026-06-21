<?php

use App\Http\Controllers\ArtworkThumbnailController;
use App\Http\Controllers\AudioStreamController;
use App\Http\Controllers\CatalogBrowseController;
use App\Http\Controllers\DashboardMetricsController;
use App\Http\Controllers\FolderBrowserController;
use Illuminate\Support\Facades\Route;

Route::get('/catalog/artists', [CatalogBrowseController::class, 'artists']);
Route::get('/catalog/albums', [CatalogBrowseController::class, 'albums']);
Route::get('/catalog/playback/albums/random', [CatalogBrowseController::class, 'randomAlbum']);
Route::get('/catalog/playback/albums/{album}/next', [CatalogBrowseController::class, 'nextAlbum']);
Route::get('/catalog/playback/tracks/random', [CatalogBrowseController::class, 'randomTrack']);
Route::get('/catalog/playback/tracks/{track}/next', [CatalogBrowseController::class, 'nextTrack']);
Route::get('/catalog/albums/{album}', [CatalogBrowseController::class, 'album']);
Route::get('/catalog/tracks', [CatalogBrowseController::class, 'tracks']);
Route::get('/catalog/genres', [CatalogBrowseController::class, 'genres']);
Route::get('/artwork/{artwork}/thumbnail', ArtworkThumbnailController::class);
Route::get('/artwork/{artwork}/original', [ArtworkThumbnailController::class, 'original']);
Route::get('/tracks/{track}/stream', AudioStreamController::class);
Route::get('/dashboard-metrics', DashboardMetricsController::class);
Route::get('/folders', FolderBrowserController::class);
