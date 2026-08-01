<?php

use App\Http\Controllers\AdminAccessController;
use App\Http\Controllers\AlbumDiscogsController;
use App\Http\Controllers\AlbumMetadataController;
use App\Http\Controllers\AlbumMusicianCreditController;
use App\Http\Controllers\AlbumPersonalMetadataController;
use App\Http\Controllers\AlbumPlaylistExportController;
use App\Http\Controllers\AlbumTrackMetadataController;
use App\Http\Controllers\ArtworkThumbnailController;
use App\Http\Controllers\AudioIntelligenceSettingsController;
use App\Http\Controllers\AudioSimilarityEvaluationController;
use App\Http\Controllers\AudioStreamController;
use App\Http\Controllers\CatalogBrowseController;
use App\Http\Controllers\CustomPlaylistExportController;
use App\Http\Controllers\DashboardMetricsController;
use App\Http\Controllers\DiscogsSettingsController;
use App\Http\Controllers\DiscogsImageController;
use App\Http\Controllers\FavoritesController;
use App\Http\Controllers\FirstRunSetupController;
use App\Http\Controllers\FolderBrowserController;
use App\Http\Controllers\LastFmSettingsController;
use App\Http\Controllers\LibraryActivityLogController;
use App\Http\Controllers\LibraryFolderController;
use App\Http\Controllers\MetadataBackupSettingsController;
use App\Http\Controllers\OnlineEnrichmentSettingsController;
use App\Http\Controllers\OnlineEnrichmentController;
use App\Http\Controllers\OwnedAlbumCopyController;
use App\Http\Controllers\PlaybackStatisticsController;
use App\Http\Controllers\PlaybackStatisticsSettingsController;
use App\Http\Controllers\PlaylistExportSettingsController;
use App\Http\Controllers\PlaylistImportController;
use App\Http\Controllers\PlaylistsController;
use App\Http\Controllers\ScanRunIssuesController;
use App\Http\Controllers\SimilarTracksController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\TrackMetadataController;
use App\Http\Controllers\TrackPlayStatisticsController;
use App\Http\Controllers\TrashController;
use Illuminate\Support\Facades\Route;

Route::get('/catalog/artists', [CatalogBrowseController::class, 'artists']);
Route::get('/catalog/artists/{artist}', [CatalogBrowseController::class, 'artist']);
Route::get('/catalog/albums', [CatalogBrowseController::class, 'albums']);
Route::get('/catalog/playback/albums/random', [CatalogBrowseController::class, 'randomAlbum']);
Route::get('/catalog/playback/albums/{album}/next', [CatalogBrowseController::class, 'nextAlbum']);
Route::get('/catalog/playback/tracks/random', [CatalogBrowseController::class, 'randomTrack']);
Route::get('/catalog/playback/tracks/{track}/next', [CatalogBrowseController::class, 'nextTrack']);
Route::get('/catalog/albums/{album}', [CatalogBrowseController::class, 'album']);
Route::patch('/albums/{album}/personal-metadata', [AlbumPersonalMetadataController::class, 'update']);
Route::patch('/albums/{album}/personal-notes', [AlbumPersonalMetadataController::class, 'updateNotes']);
Route::get('/albums/{album}/playlist-export', [AlbumPlaylistExportController::class, 'show']);
Route::post('/albums/{album}/playlist-export', [AlbumPlaylistExportController::class, 'store']);
Route::get('/albums/{album}/discogs/candidates', [AlbumDiscogsController::class, 'candidates']);
Route::get('/albums/{album}/discogs/releases/{releaseId}/collection-instances', [AlbumDiscogsController::class, 'collectionInstances']);
Route::post('/albums/{album}/owned-copies', [OwnedAlbumCopyController::class, 'store']);
Route::patch('/albums/{album}/owned-copies/{ownedAlbumCopy}', [OwnedAlbumCopyController::class, 'update']);
Route::delete('/albums/{album}/owned-copies/{ownedAlbumCopy}', [OwnedAlbumCopyController::class, 'destroy']);
Route::get('/albums/{album}/owned-copies/{ownedAlbumCopy}/discogs', [AlbumDiscogsController::class, 'show']);
Route::put('/albums/{album}/owned-copies/{ownedAlbumCopy}/discogs', [AlbumDiscogsController::class, 'link']);
Route::post('/albums/{album}/owned-copies/{ownedAlbumCopy}/discogs/refresh', [AlbumDiscogsController::class, 'refresh']);
Route::delete('/albums/{album}/owned-copies/{ownedAlbumCopy}/discogs', [AlbumDiscogsController::class, 'unlink']);
Route::get('/albums/{album}/artwork/original', [ArtworkThumbnailController::class, 'albumOriginal']);
Route::post('/albums/{album}/metadata/preview', [AlbumMetadataController::class, 'preview']);
Route::post('/albums/{album}/metadata-edits', [AlbumMetadataController::class, 'store']);
Route::post('/albums/{album}/tracks/metadata/preview', [AlbumTrackMetadataController::class, 'preview']);
Route::post('/albums/{album}/tracks/metadata-edits', [AlbumTrackMetadataController::class, 'store']);
Route::get('/albums/{album}/musician-credits', [AlbumMusicianCreditController::class, 'index']);
Route::post('/albums/{album}/musician-credits', [AlbumMusicianCreditController::class, 'store']);
Route::put('/albums/{album}/musician-credits/discogs-source', [AlbumMusicianCreditController::class, 'selectDiscogsSource']);
Route::delete('/albums/{album}/musician-credits/discogs-source', [AlbumMusicianCreditController::class, 'clearDiscogsSource']);
Route::patch('/albums/{album}/musician-credits/{manualCredit}', [AlbumMusicianCreditController::class, 'update']);
Route::delete('/albums/{album}/musician-credits/{manualCredit}', [AlbumMusicianCreditController::class, 'destroy']);
Route::put('/albums/{album}/musician-credits/suppressions/{sourceKey}', [AlbumMusicianCreditController::class, 'suppress'])
    ->where('sourceKey', '[a-f0-9]{64}');
Route::delete('/albums/{album}/musician-credits/suppressions/{sourceKey}', [AlbumMusicianCreditController::class, 'restore'])
    ->where('sourceKey', '[a-f0-9]{64}');
Route::get('/catalog/tracks', [CatalogBrowseController::class, 'tracks']);
Route::get('/catalog/tracks/{track}', [CatalogBrowseController::class, 'track']);
Route::get('/catalog/genres', [CatalogBrowseController::class, 'genres']);
Route::get('/catalog/library-roots/{libraryRoot}/folders', [LibraryFolderController::class, 'show']);
Route::get('/catalog/library-roots/{libraryRoot}/folder-tracks', [LibraryFolderController::class, 'tracks']);
Route::patch('/library_roots/{libraryRoot}/entries/rename', [LibraryFolderController::class, 'rename']);
Route::get('/artwork/{artwork}/thumbnail', ArtworkThumbnailController::class);
Route::get('/tracks/{track}/stream', AudioStreamController::class);
Route::get('/audio-intelligence/tracks/{track}/similar', SimilarTracksController::class);
Route::post('/tracks/{track}/plays', [TrackPlayStatisticsController::class, 'store']);
Route::post('/tracks/{track}/metadata/preview', [TrackMetadataController::class, 'preview']);
Route::post('/tracks/{track}/metadata-edits', [TrackMetadataController::class, 'store']);
Route::get('/metadata-edits/{metadataEditJob}', [TrackMetadataController::class, 'show']);
Route::get('/statistics/recent-plays', [PlaybackStatisticsController::class, 'recentPlays']);
Route::get('/statistics/most-played-tracks', [PlaybackStatisticsController::class, 'mostPlayedTracks']);
Route::get('/statistics/most-played-albums', [PlaybackStatisticsController::class, 'mostPlayedAlbums']);
Route::get('/statistics/tracks/{track}/recent-plays', [PlaybackStatisticsController::class, 'trackRecentPlays']);
Route::get('/settings/playback-statistics', [PlaybackStatisticsSettingsController::class, 'show']);
Route::get('/settings/audio-intelligence', [AudioIntelligenceSettingsController::class, 'show']);
Route::patch('/settings/audio-intelligence', [AudioIntelligenceSettingsController::class, 'update']);
Route::post(
    '/settings/audio-intelligence/validation-runs',
    [AudioIntelligenceSettingsController::class, 'prepareValidationSample'],
);
Route::post('/settings/audio-intelligence/expansions', [AudioIntelligenceSettingsController::class, 'prepareExpansion']);
Route::post('/settings/audio-intelligence/collections', [AudioIntelligenceSettingsController::class, 'prepareCollection']);
Route::post('/settings/audio-intelligence/analyzer/test', [AudioIntelligenceSettingsController::class, 'testAnalyzer']);
Route::post(
    '/settings/audio-intelligence/benchmarks',
    [AudioIntelligenceSettingsController::class, 'startBenchmark'],
);
Route::post(
    '/settings/audio-intelligence/benchmarks/{audioAnalyzerBenchmark}/cancel',
    [AudioIntelligenceSettingsController::class, 'cancelBenchmark'],
);
Route::post(
    '/settings/audio-intelligence/runs/{audioAnalysisRun}/start',
    [AudioIntelligenceSettingsController::class, 'startRun'],
);
Route::post(
    '/settings/audio-intelligence/runs/{audioAnalysisRun}/cancel',
    [AudioIntelligenceSettingsController::class, 'cancelRun'],
);
Route::post(
    '/settings/audio-intelligence/runs/{audioAnalysisRun}/pause',
    [AudioIntelligenceSettingsController::class, 'pauseRun'],
);
Route::post(
    '/settings/audio-intelligence/runs/{audioAnalysisRun}/resume',
    [AudioIntelligenceSettingsController::class, 'resumeRun'],
);
Route::get(
    '/settings/audio-intelligence/evaluation',
    [AudioSimilarityEvaluationController::class, 'index'],
);
Route::get(
    '/settings/audio-intelligence/evaluation/{track}',
    [AudioSimilarityEvaluationController::class, 'show'],
);
Route::put(
    '/settings/audio-intelligence/evaluation/{track}/matches/{candidate}/feedback',
    [AudioSimilarityEvaluationController::class, 'storeFeedback'],
);
Route::delete(
    '/settings/audio-intelligence/evaluation/{track}/matches/{candidate}/feedback',
    [AudioSimilarityEvaluationController::class, 'destroyFeedback'],
);
Route::get('/settings/first-run', [FirstRunSetupController::class, 'show']);
Route::patch('/settings/first-run', [FirstRunSetupController::class, 'update']);
Route::get('/settings/access', AdminAccessController::class);
Route::patch('/settings/playback-statistics', [PlaybackStatisticsSettingsController::class, 'update']);
Route::get('/settings/playlist-exports', [PlaylistExportSettingsController::class, 'show']);
Route::patch('/settings/playlist-exports', [PlaylistExportSettingsController::class, 'update']);
Route::post('/settings/playlist-exports/locations', [PlaylistExportSettingsController::class, 'storeLocation']);
Route::patch(
    '/settings/playlist-exports/locations/{playlistExportLocation}',
    [PlaylistExportSettingsController::class, 'updateLocation'],
);
Route::post(
    '/settings/playlist-exports/locations/{playlistExportLocation}/default',
    [PlaylistExportSettingsController::class, 'setDefault'],
);
Route::delete(
    '/settings/playlist-exports/locations/{playlistExportLocation}',
    [PlaylistExportSettingsController::class, 'destroyLocation'],
);
Route::get('/settings/metadata-backups', [MetadataBackupSettingsController::class, 'show']);
Route::patch('/settings/metadata-backups', [MetadataBackupSettingsController::class, 'update']);
Route::get('/settings/lastfm', [LastFmSettingsController::class, 'show']);
Route::get('/settings/lastfm/deliveries', [LastFmSettingsController::class, 'deliveries']);
Route::post('/settings/lastfm/connect', [LastFmSettingsController::class, 'connect']);
Route::post('/settings/lastfm/complete', [LastFmSettingsController::class, 'complete']);
Route::patch('/settings/lastfm', [LastFmSettingsController::class, 'update']);
Route::delete('/settings/lastfm', [LastFmSettingsController::class, 'disconnect']);
Route::get('/settings/discogs', [DiscogsSettingsController::class, 'show']);
Route::post('/settings/discogs/connect', [DiscogsSettingsController::class, 'connect']);
Route::delete('/settings/discogs', [DiscogsSettingsController::class, 'disconnect']);
Route::get('/discogs/images/{hash}', DiscogsImageController::class)
    ->where('hash', '[a-f0-9]{64}');
Route::get('/settings/online-enrichment', [OnlineEnrichmentSettingsController::class, 'show']);
Route::patch('/settings/online-enrichment', [OnlineEnrichmentSettingsController::class, 'update']);
Route::delete('/settings/online-enrichment/cache', [OnlineEnrichmentSettingsController::class, 'clearCache']);
Route::post('/settings/online-enrichment/providers/{provider}/test', [OnlineEnrichmentSettingsController::class, 'testProvider']);
Route::get('/settings/system-health', SystemHealthController::class);
Route::get('/library-activity', LibraryActivityLogController::class);
Route::get('/scan_runs/{scanRun}/issues', ScanRunIssuesController::class);
Route::get('/enrichment/tracks/{track}/information', [OnlineEnrichmentController::class, 'information']);
Route::get('/enrichment/tracks/{track}/identity', [OnlineEnrichmentController::class, 'identity']);
Route::get('/enrichment/tracks/{track}/artist-image', [OnlineEnrichmentController::class, 'artistImage']);
Route::get('/enrichment/tracks/{track}/artist-image-information', [OnlineEnrichmentController::class, 'artistImageInformation']);
Route::get('/enrichment/tracks/{track}/lyrics', [OnlineEnrichmentController::class, 'lyrics']);
Route::get('/enrichment/albums/{album}/musicians', [OnlineEnrichmentController::class, 'albumMusicians']);
Route::put('/enrichment/albums/{album}/musicians/release', [OnlineEnrichmentController::class, 'resolveAlbumMusicians']);
Route::get('/dashboard-metrics', DashboardMetricsController::class);
Route::get('/folders', FolderBrowserController::class);
Route::get('/trash/tracks', [TrashController::class, 'index']);
Route::delete('/trash/tracks', [TrashController::class, 'destroyMany']);
Route::delete('/trash/tracks/{track}', [TrashController::class, 'destroy']);
Route::get('/favorites', [FavoritesController::class, 'ids']);
Route::get('/favorites/tracks', [FavoritesController::class, 'tracks']);
Route::post('/favorites/tracks/{track}', [FavoritesController::class, 'addTrack']);
Route::delete('/favorites/tracks/{track}', [FavoritesController::class, 'removeTrack']);
Route::get('/favorites/albums', [FavoritesController::class, 'albums']);
Route::post('/favorites/albums/{album}', [FavoritesController::class, 'addAlbum']);
Route::delete('/favorites/albums/{album}', [FavoritesController::class, 'removeAlbum']);
Route::get('/playlist-folders', [PlaylistsController::class, 'folders']);
Route::post('/playlist-folders', [PlaylistsController::class, 'createFolder']);
Route::patch('/playlist-folders/{folder}', [PlaylistsController::class, 'updateFolder']);
Route::delete('/playlist-folders/{folder}', [PlaylistsController::class, 'deleteFolder']);
Route::get('/playlists', [PlaylistsController::class, 'playlists']);
Route::post('/playlists', [PlaylistsController::class, 'createPlaylist']);
Route::post('/playlists/import', PlaylistImportController::class);
Route::get('/playlists/memberships', [PlaylistsController::class, 'memberships']);
Route::get('/playlists/{playlist}', [PlaylistsController::class, 'playlist']);
Route::get('/playlists/{playlist}/file-export', [CustomPlaylistExportController::class, 'show']);
Route::post('/playlists/{playlist}/file-export', [CustomPlaylistExportController::class, 'store']);
Route::patch('/playlists/{playlist}', [PlaylistsController::class, 'updatePlaylist']);
Route::delete('/playlists/{playlist}', [PlaylistsController::class, 'deletePlaylist']);
Route::post('/playlists/{playlist}/tracks', [PlaylistsController::class, 'addTracks']);
Route::post('/playlists/{playlist}/tracks/{track}', [PlaylistsController::class, 'addTrack']);
Route::delete('/playlists/{playlist}/items', [PlaylistsController::class, 'removeItems']);
Route::delete('/playlists/{playlist}/items/{item}', [PlaylistsController::class, 'removeItem']);
Route::patch('/playlists/{playlist}/items/reorder', [PlaylistsController::class, 'reorderItems']);
