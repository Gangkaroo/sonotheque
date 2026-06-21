<?php

use App\Http\Controllers\ArtworkThumbnailController;
use App\Http\Controllers\AudioStreamController;
use App\Http\Controllers\CatalogBrowseController;
use App\Http\Controllers\DashboardMetricsController;
use App\Http\Controllers\FolderBrowserController;
use Illuminate\Support\Facades\Route;

Route::get('/catalog/artists', [CatalogBrowseController::class, 'artists']);
Route::get('/catalog/albums', [CatalogBrowseController::class, 'albums']);
Route::get('/catalog/tracks', [CatalogBrowseController::class, 'tracks']);
Route::get('/catalog/genres', [CatalogBrowseController::class, 'genres']);
Route::get('/artwork/{artwork}/thumbnail', ArtworkThumbnailController::class);
Route::get('/tracks/{track}/stream', AudioStreamController::class);
Route::get('/dashboard-metrics', DashboardMetricsController::class);
Route::get('/folders', FolderBrowserController::class);
