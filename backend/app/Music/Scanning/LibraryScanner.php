<?php

namespace App\Music\Scanning;

use App\Enums\MediaFileStatus;
use App\Enums\ScanStatus;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\MediaFile;
use App\Models\ScanRun;
use App\Models\ScanRunIssue;
use App\Models\Track;
use App\Music\Artwork\AlbumArtworkManager;
use App\Music\Catalog\GenreResolver;
use App\Music\PlaybackStatistics\ImportedPlayStatistics;
use App\Music\PlaybackStatistics\PlaybackStatisticsImporter;
use App\Music\PlaybackStatistics\PlaybackStatisticsTagReader;
use App\Music\Playlists\PlaylistFileSynchronizationDispatcher;
use App\Music\Ratings\ImportedRatingTags;
use App\Music\Ratings\RatingTagReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class LibraryScanner
{
    public const METADATA_PARSER_VERSION = 3;

    private const CANCELLATION_CHECK_INTERVAL = 10;

    private const DISCOVERY_UPDATE_INTERVAL = 250;

    private const MODEL_CACHE_RESET_INTERVAL = 250;

    private const MAX_REPORTED_ISSUES = 50;

    private const ISSUE_FLUSH_INTERVAL = 100;

    private const PROGRESS_UPDATE_INTERVAL = 25;

    private const UNCHANGED_FLUSH_INTERVAL = 1000;

    /** @var array<int, true> */
    private array $artworkSynced = [];

    /** @var array<string, Album> */
    private array $albumCache = [];

    /** @var array<int, Album> */
    private array $albumIdCache = [];

    /** @var array<string, Artist> */
    private array $artistCache = [];

    /** @var array<string, ScanMediaFileState> */
    private array $existingFiles = [];

    /** @var array<string, ?string> */
    private array $fingerprintsByPath = [];

    /** @var array<string, true> */
    private array $fingerprintFailures = [];

    /** @var array<string, int> */
    private array $newFingerprintCounts = [];

    /** @var list<DiscoveredAudioFile> */
    private array $newFiles = [];

    /** @var array<string, list<array{pathHash: string, libraryRootId: int, state: ScanMediaFileState}>> */
    private array $staleFilesByFingerprint = [];

    /** @var array<string, true> */
    private array $discoveredPathHashes = [];

    /** @var array<string, Genre> */
    private array $genreCache = [];

    /** @var list<int> */
    private array $unchangedFileIds = [];

    /** @var array<int, true> */
    private array $unchangedArtworkAlbums = [];

    private int $unchangedFilesFastTracked = 0;

    private bool $importPlayStatisticsFromTags = false;

    private int $playStatisticsImported = 0;

    private bool $importRatingsFromTags = false;

    private int $ratingsImported = 0;

    private int $issueRecordCount = 0;

    /** @var list<array{scan_run_id: int, code: string, severity: string, message: string, path: ?string, occurrence_count: int, created_at: mixed, updated_at: mixed}> */
    private array $pendingScanIssues = [];

    private ?int $pendingScanRunId = null;

    private ?string $subtreePath = null;

    /** @var list<string>|null */
    private ?array $scanPaths = null;

    /** @var list<string>|null */
    private ?array $missingPaths = null;

    private ?int $pendingLibraryRootId = null;

    /** @var array<int, array{trackId: int, statistics: ImportedPlayStatistics}> */
    private array $pendingPlayStatisticsImports = [];

    /** @var array<int, true> */
    private array $pendingPlayStatisticsImportMediaFileIds = [];

    /** @var array<int, true> */
    private array $pendingRatingImportMediaFileIds = [];

    public function __construct(
        private readonly AudioFileDiscoverer $discoverer,
        private readonly ScanDiscoveryManifest $discoveryManifest,
        private readonly AudioMetadataReader $metadataReader,
        private readonly AudioContentFingerprinter $contentFingerprinter,
        private readonly ArtistName $artistName,
        private readonly AlbumArtworkManager $artworkManager,
        private readonly PlaybackStatisticsTagReader $playStatisticsTagReader,
        private readonly PlaybackStatisticsImporter $playStatisticsImporter,
        private readonly RatingTagReader $ratingTagReader,
        private readonly PlaylistFileSynchronizationDispatcher $playlistSynchronizationDispatcher,
        private readonly LibraryPathGuard $pathGuard,
        private readonly LibraryActivityLogger $activityLogger,
        private readonly GenreResolver $genreResolver,
    ) {
    }

    public function scan(ScanRun $scanRun): void
    {
        $startedAt = CarbonImmutable::now();
        $claimed = ScanRun::query()
            ->whereKey($scanRun->id)
            ->where('status', ScanStatus::Pending->value)
            ->whereNull('cancel_requested_at')
            ->update([
                'status' => ScanStatus::Running,
                'started_at' => $startedAt,
                'finished_at' => null,
                'summary' => ['phase' => 'counting'],
            ]);

        if ($claimed === 0) {
            return;
        }

        $scanRun->refresh()->loadMissing('libraryRoot');
        $this->pendingLibraryRootId = $scanRun->library_root_id;
        $this->subtreePath = $scanRun->subtree_path;
        $this->scanPaths = $scanRun->scan_paths;
        $this->missingPaths = $scanRun->missing_paths;
        $this->prepareScanCaches($scanRun);
        $issues = [];
        $progress = [
            'files_discovered' => 0,
            'files_processed' => 0,
            'files_added' => 0,
            'files_updated' => 0,
            'warning_count' => 0,
            'error_count' => 0,
        ];
        $existingFileCount = count($this->existingFiles);

        try {
            $diagnostics = new DiscoveryDiagnostics();
            $this->discoveryManifest->start($scanRun->id);

            foreach ($this->discover($scanRun, $diagnostics) as $file) {
                $progress['files_discovered']++;
                $pathHash = $this->pathHash($file->relativePath);
                $this->discoveredPathHashes[$pathHash] = true;
                $existing = $this->existingFiles[$pathHash] ?? null;

                if ($existing === null) {
                    $this->newFiles[] = $file;
                }

                if ($existing !== null && $this->isUnchangedFile($file, $existing)) {
                    $albumId = $existing->albumId;

                    if ($albumId !== null && ! isset($this->unchangedArtworkAlbums[$albumId])) {
                        $this->unchangedArtworkAlbums[$albumId] = true;
                        $this->discoveryManifest->append($file);
                    } else {
                        $warnings = $this->fastTrackUnchangedFile($existing, $startedAt);
                        $progress['files_processed']++;
                        $this->unchangedFilesFastTracked++;

                        if ($warnings !== []) {
                            $progress['warning_count'] += count($warnings);

                            foreach ($warnings as $warning) {
                                $this->addIssue(
                                    $issues,
                                    'file_warning',
                                    'warning',
                                    $warning,
                                    $file->relativePath,
                                );
                            }
                        }
                    }
                } else {
                    $this->discoveryManifest->append($file);
                }

                if ($progress['files_discovered'] % self::DISCOVERY_UPDATE_INTERVAL === 0
                    && $this->cancellationRequested($scanRun)) {
                    $this->flushPendingFileChanges($startedAt);
                    $this->cancelScan($scanRun, $progress, $issues);

                    return;
                }

                if ($progress['files_discovered'] % self::DISCOVERY_UPDATE_INTERVAL === 0) {
                    $scanRun->update(array_merge($progress, [
                        'summary' => $this->summary('counting', $issues),
                    ]));
                }
            }

            $this->flushPendingFileChanges($startedAt);
            $scanRun->update(array_merge($progress, [
                'summary' => $this->summary('scanning', $issues),
            ]));
            $this->prepareMoveCandidates();
            if (! $this->fingerprintNewFilesForMoveDetection($scanRun)) {
                $this->cancelScan($scanRun, $progress, $issues);

                return;
            }
            foreach ($this->discoveryManifest->files($scanRun->id) as $file) {
                if ($progress['files_processed'] % self::CANCELLATION_CHECK_INTERVAL === 0
                    && $this->cancellationRequested($scanRun)) {
                    $this->flushPendingFileChanges($startedAt);
                    $this->cancelScan($scanRun, $progress, $issues);

                    return;
                }

                try {
                    $result = $this->processFile($scanRun, $file, $startedAt);

                    if ($result['changed']) {
                        $progress[$result['created'] ? 'files_added' : 'files_updated']++;
                    }

                    if ($result['warnings'] !== []) {
                        $progress['warning_count'] += count($result['warnings']);

                        foreach ($result['warnings'] as $warning) {
                            $this->addIssue($issues, 'file_warning', 'warning', $warning, $file->relativePath);
                        }
                    }
                } catch (Throwable $exception) {
                    $message = $this->exceptionMessage($exception);
                    $warnings = $this->recordFileError($scanRun, $file, $startedAt, $exception);
                    $progress['error_count']++;
                    $this->addIssue(
                        $issues,
                        'file_error',
                        'error',
                        $message,
                        $file->relativePath,
                    );

                    if ($warnings !== []) {
                        $progress['warning_count'] += count($warnings);

                        foreach ($warnings as $warning) {
                            $this->addIssue($issues, 'file_warning', 'warning', $warning, $file->relativePath);
                        }
                    }
                }

                $progress['files_processed']++;

                if ($progress['files_processed'] % self::PROGRESS_UPDATE_INTERVAL === 0) {
                    $this->flushProgress($scanRun, $progress, $issues, $startedAt);
                }

                if ($progress['files_processed'] % self::MODEL_CACHE_RESET_INTERVAL === 0) {
                    $this->resetModelCaches();
                }
            }

            $this->flushPendingFileChanges($startedAt);

            foreach ($diagnostics->issues() as $issue) {
                $this->addIssue(
                    $issues,
                    $issue['code'],
                    $issue['severity'],
                    $issue['message'],
                    $issue['path'],
                    $issue['count'],
                );
            }

            if ($diagnostics->warningCount() > 0) {
                $progress['warning_count'] += $diagnostics->warningCount();
            }

            if ($this->fingerprintFailures !== []) {
                $failureCount = count($this->fingerprintFailures);
                $this->addIssue(
                    $issues,
                    'audio_fingerprint_failed',
                    'warning',
                    'Audio-content fingerprints could not be calculated for some files. Move detection was skipped for those files.',
                    array_key_first($this->fingerprintFailures),
                    $failureCount,
                );
                $progress['warning_count'] += $failureCount;
            }

            $canRemoveStaleFiles = $this->canRemoveStaleFiles($diagnostics);

            if ($progress['files_discovered'] === 0 && $existingFileCount > 0 && ! $canRemoveStaleFiles) {
                $message = 'No supported audio files were found, although this root previously contained indexed files. Existing file availability was preserved.';
                $this->addIssue($issues, 'empty_scan_preserved', 'error', $message);
                $progress['error_count']++;
                $this->flushScanIssues();
                $scanRun->update(array_merge($progress, [
                    'status' => ScanStatus::Failed,
                    'finished_at' => now(),
                    'summary' => $this->summary('failed', $issues, $message),
                ]));

                return;
            }

            if ($progress['files_discovered'] === 0 && ! $this->isDeltaScan()) {
                $this->addIssue(
                    $issues,
                    'no_music_files',
                    'warning',
                    'No supported audio files were found in the expected Artist/Album folder layout.',
                );
                $progress['warning_count']++;
            }

            $staleFiles = MediaFile::query()
                ->where('library_root_id', $scanRun->library_root_id)
                ->where('last_seen_at', '<', $startedAt);
            $this->scopeMediaFilesToScan($staleFiles, $scanRun);
            $removed = $this->markStaleFilesMissing($staleFiles, $diagnostics);

            if ($removed > 0) {
                $this->addIssue(
                    $issues,
                    'files_unavailable',
                    'warning',
                    'Previously indexed audio files were not found and are now unavailable.',
                    count: $removed,
                );
                $progress['warning_count'] += $removed;
            }

            if (! $canRemoveStaleFiles
                && (clone $staleFiles)
                    ->where('status', '!=', MediaFileStatus::Missing->value)
                    ->exists()) {
                $this->addIssue(
                    $issues,
                    'stale_cleanup_preserved',
                    'warning',
                    'Some folders or files could not be read, so unseen catalog records were preserved.',
                );
                $progress['warning_count']++;
            }

            $this->deleteAlbumsWithoutMediaFiles($scanRun);
            $this->deleteOrphanedCatalogData();
            $this->flushScanIssues();

            if ($scanRun->subtree_path === null && ! $this->isDeltaScan()) {
                $scanRun->libraryRoot->update(['last_scanned_at' => now()]);
            }
            $scanRun->update(array_merge($progress, [
                'status' => ScanStatus::Completed,
                'files_removed' => $removed,
                'finished_at' => now(),
                'summary' => $this->summary('completed', $issues),
            ]));
        } catch (Throwable $exception) {
            $this->flushPendingFileChanges($startedAt);
            $this->addIssue($issues, 'scan_failed', 'error', $exception->getMessage());
            $progress['error_count']++;
            $this->flushScanIssues();
            $scanRun->update(array_merge($progress, [
                'status' => ScanStatus::Failed,
                'finished_at' => now(),
                'summary' => $this->summary('failed', $issues, $exception->getMessage()),
            ]));

            throw $exception;
        } finally {
            $this->discoveryManifest->delete($scanRun->id);
        }
    }

    /** @return array{created: bool, changed: bool, warnings: list<string>} */
    private function processFile(ScanRun $scanRun, DiscoveredAudioFile $file, CarbonImmutable $seenAt): array
    {
        $pathHash = $this->pathHash($file->relativePath);
        $existing = $this->existingFiles[$pathHash] ?? null;

        if ($existing === null) {
            $existing = $this->reconcileMovedFile($scanRun, $file, $pathHash);
        }

        if ($existing !== null && $this->isUnchangedFile($file, $existing)) {
            $album = $this->findAlbumById($existing->albumId);

            if ($album !== null) {
                $this->unchangedFileIds[] = $existing->id;
                $warnings = $this->syncArtwork($album, $scanRun, audioPath: $file->absolutePath);
                $trackId = $existing->trackId;
                $imported = $existing->playStatistics;
                if ($trackId !== null && $imported !== null) {
                    $warnings = array_merge(
                        $warnings,
                        $this->importPlayStatistics($trackId, $imported, $existing->id),
                    );
                }
                if ($trackId !== null && $existing->ratingTags !== null) {
                    $warnings = array_merge(
                        $warnings,
                        $this->importRatings(
                            $trackId,
                            $existing->albumId,
                            $existing->ratingTags,
                            $existing->id,
                        ),
                    );
                }

                return ['created' => false, 'changed' => false, 'warnings' => $warnings];
            }
        }

        $contentFingerprint = $this->fingerprint($file);
        $previousRatings = $this->previousRatingTags($existing);
        $metadata = $this->metadataReader->read($file->absolutePath);

        try {
            $processed = DB::transaction(function () use ($scanRun, $file, $seenAt, $pathHash, $existing, $metadata, $contentFingerprint): array {
                $artistName = $metadata->albumArtist ?? $metadata->artists[0] ?? $file->artistFolder;
                $artist = $this->findOrCreateArtist($artistName);
                $albumHash = $this->pathHash($file->albumRelativePath);
                $album = $this->albumCache[$albumHash] ??= $this->albumForFile($scanRun, $existing, $albumHash);
                $albumTitle = $this->limited($metadata->album ?? $file->albumFolder, 512);
                $album->fill([
                    'primary_artist_id' => $artist->id,
                    'title' => $albumTitle,
                    'sort_title' => $albumTitle,
                    'relative_path' => $file->albumRelativePath,
                    'relative_path_hash' => $albumHash,
                    'original_release_year' => $metadata->originalReleaseYear ?? $album->original_release_year,
                    'disc_total' => $metadata->discTotal ?? $album->disc_total,
                    'metadata' => [
                        'folder_artist' => $file->artistFolder,
                        'folder_album' => $file->albumFolder,
                        'embedded_artwork_present' => $metadata->embeddedArtwork !== null,
                    ],
                ]);
                if (! $album->exists || $album->isDirty()) {
                    $album->save();
                }

                $mediaFile = MediaFile::updateOrCreate(
                    [
                        'library_root_id' => $scanRun->library_root_id,
                        'relative_path_hash' => $pathHash,
                    ],
                    [
                        'album_id' => $album->id,
                        'relative_path' => $file->relativePath,
                        'file_size' => $file->fileSize,
                        'modified_at' => CarbonImmutable::createFromTimestamp($file->modifiedAt),
                        'mime_type' => $metadata->mimeType,
                        'container' => $metadata->container,
                        'codec' => $metadata->codec,
                        'bitrate' => $metadata->bitrate,
                        'sample_rate' => $metadata->sampleRate,
                        'channels' => $metadata->channels,
                        'status' => MediaFileStatus::Available,
                        'last_seen_at' => $seenAt,
                        'scan_error' => null,
                        'raw_metadata' => $metadata->rawMetadata,
                        'metadata_parser_version' => self::METADATA_PARSER_VERSION,
                        'content_fingerprint' => $contentFingerprint,
                        'content_fingerprint_version' => AudioContentFingerprinter::VERSION,
                        'play_statistics_import_version' => null,
                        'rating_tags_import_version' => null,
                    ],
                );

                $track = Track::updateOrCreate(
                    ['media_file_id' => $mediaFile->id],
                    [
                        'album_id' => $album->id,
                        'title' => $this->limited($metadata->title ?? pathinfo($file->absolutePath, PATHINFO_FILENAME), 512),
                        'sort_title' => $this->limited($metadata->title ?? pathinfo($file->absolutePath, PATHINFO_FILENAME), 512),
                        'duration_ms' => $metadata->durationMs,
                        'track_number' => $metadata->trackNumber,
                        'disc_number' => $metadata->discNumber,
                        'year' => $metadata->year,
                        'comment' => $metadata->comment,
                        'composers' => $metadata->composers ?: null,
                        'performers' => $metadata->performers ?: null,
                        'metadata' => null,
                    ],
                );

                $trackArtists = $metadata->artists === [] ? [$artistName] : $metadata->artists;
                $artistPivots = [];

                foreach (array_values(array_unique($trackArtists)) as $position => $trackArtistName) {
                    $trackArtist = $this->findOrCreateArtist($trackArtistName);
                    $artistPivots[$trackArtist->id] = ['role' => 'primary', 'position' => $position];
                }

                $track->artists()->sync($artistPivots);
                $genreIds = collect($metadata->genres)
                    ->map(fn (string $genre): string => trim($genre))
                    ->filter()
                    ->map(fn (string $genre): int => $this->findOrCreateGenre($genre)->id)
                    ->unique()
                    ->values()
                    ->all();

                $track->genres()->sync($genreIds);

                return [
                    'album' => $album,
                    'mediaFile' => $mediaFile,
                    'track' => $track,
                    'created' => $existing === null,
                ];
            });
        } catch (Throwable $exception) {
            $this->resetModelCaches();

            throw $exception;
        }

        $this->albumIdCache[$processed['album']->id] = $processed['album'];
        $importWarnings = $this->importPlayStatistics(
            $processed['track'],
            $this->playStatisticsTagReader->read($metadata->rawMetadata),
            $processed['mediaFile']->id,
        );
        $newRatings = $this->ratingTagReader->read($metadata->rawMetadata);
        $ratingWarnings = $this->importRatings(
            $processed['track'],
            $processed['album']->id,
            $newRatings,
            $processed['mediaFile']->id,
            overwriteTrack: $this->tagChanged($previousRatings, $newRatings, 'track'),
            overwriteAlbum: $this->tagChanged($previousRatings, $newRatings, 'album'),
        );

        return [
            'created' => $processed['created'],
            'changed' => true,
            'warnings' => array_merge(
                $metadata->warnings,
                $importWarnings,
                $ratingWarnings,
                $this->syncArtwork(
                    $processed['album'],
                    $scanRun,
                    metadata: $metadata,
                    embeddedInspected: true,
                ),
            ),
        ];
    }

    private function isUnchangedFile(
        DiscoveredAudioFile $file,
        ScanMediaFileState $existing,
    ): bool {
        return ! $existing->moved
            && $existing->albumId !== null
            && $existing->albumRelativePathHash === $this->pathHash($file->albumRelativePath)
            && $existing->status === MediaFileStatus::Available
            && $existing->fileSize === $file->fileSize
            && $existing->modifiedAt === $file->modifiedAt
            && $existing->metadataParserVersion === self::METADATA_PARSER_VERSION;
    }

    /** @return list<string> */
    private function fastTrackUnchangedFile(
        ScanMediaFileState $existing,
        CarbonImmutable $seenAt,
    ): array {
        $this->unchangedFileIds[] = $existing->id;
        $warnings = [];

        if ($existing->trackId !== null && $existing->playStatistics !== null) {
            $warnings = $this->importPlayStatistics(
                $existing->trackId,
                $existing->playStatistics,
                $existing->id,
            );
        }
        if ($existing->trackId !== null && $existing->ratingTags !== null) {
            $warnings = array_merge(
                $warnings,
                $this->importRatings(
                    $existing->trackId,
                    $existing->albumId,
                    $existing->ratingTags,
                    $existing->id,
                ),
            );
        }

        if (count($this->unchangedFileIds) >= self::UNCHANGED_FLUSH_INTERVAL) {
            $this->flushPendingFileChanges($seenAt);
        }

        return $warnings;
    }

    /** @return list<string> */
    private function recordFileError(ScanRun $scanRun, DiscoveredAudioFile $file, CarbonImmutable $seenAt, Throwable $exception): array
    {
        $message = $this->exceptionMessage($exception, 4096);

        $album = DB::transaction(function () use ($scanRun, $file, $seenAt, $message): Album {
            $artist = $this->findOrCreateArtist($file->artistFolder);
            $album = Album::firstOrCreate(
                [
                    'library_root_id' => $scanRun->library_root_id,
                    'relative_path_hash' => $this->pathHash($file->albumRelativePath),
                ],
                [
                    'primary_artist_id' => $artist->id,
                    'title' => $this->limited($file->albumFolder, 512),
                    'sort_title' => $this->limited($file->albumFolder, 512),
                    'relative_path' => $file->albumRelativePath,
                    'metadata' => [
                        'folder_artist' => $file->artistFolder,
                        'folder_album' => $file->albumFolder,
                    ],
                ],
            );

            MediaFile::updateOrCreate(
                [
                    'library_root_id' => $scanRun->library_root_id,
                    'relative_path_hash' => $this->pathHash($file->relativePath),
                ],
                [
                    'album_id' => $album->id,
                    'relative_path' => $file->relativePath,
                    'file_size' => $file->fileSize,
                    'modified_at' => CarbonImmutable::createFromTimestamp($file->modifiedAt),
                    'status' => MediaFileStatus::Error,
                    'last_seen_at' => $seenAt,
                    'scan_error' => mb_substr($message, 0, 65535),
                    'content_fingerprint' => $this->fingerprint($file),
                    'content_fingerprint_version' => AudioContentFingerprinter::VERSION,
                ],
            );

            return $album;
        });

        return $this->syncArtwork($album, $scanRun, embeddedInspected: true);
    }

    /** @return list<string> */
    private function syncArtwork(
        Album $album,
        ScanRun $scanRun,
        ?AudioMetadata $metadata = null,
        ?string $audioPath = null,
        bool $embeddedInspected = false,
    ): array {
        if (isset($this->artworkSynced[$album->id])) {
            return [];
        }

        $this->artworkSynced[$album->id] = true;
        $result = $this->artworkManager->sync(
            $album,
            $scanRun->libraryRoot,
            $metadata?->embeddedArtwork,
            $embeddedInspected,
        );

        if (! $result->requiresEmbeddedFallback) {
            return $result->warnings;
        }

        if ($audioPath === null) {
            return $result->warnings;
        }

        if (($album->metadata['embedded_artwork_present'] ?? null) === false) {
            $fallback = $this->artworkManager->sync($album, $scanRun->libraryRoot, embeddedInspected: true);

            return array_merge($result->warnings, $fallback->warnings);
        }

        $metadata = $this->metadataReader->read($audioPath);
        $albumMetadata = $album->metadata ?? [];
        $albumMetadata['embedded_artwork_present'] = $metadata->embeddedArtwork !== null;
        $album->update(['metadata' => $albumMetadata]);
        $fallback = $this->artworkManager->sync(
            $album,
            $scanRun->libraryRoot,
            $metadata->embeddedArtwork,
            embeddedInspected: true,
        );

        return array_merge($result->warnings, $metadata->warnings, $fallback->warnings);
    }

    private function cancellationRequested(ScanRun $scanRun): bool
    {
        return ScanRun::query()->whereKey($scanRun->id)->value('cancel_requested_at') !== null;
    }

    /**
     * @param  array<string, int>  $progress
     * @param  list<array{code: string, severity: string, message: string, path?: string, count?: int}>  $issues
     */
    private function cancelScan(ScanRun $scanRun, array $progress, array $issues): void
    {
        $this->flushScanIssues();
        $scanRun->update(array_merge($progress, [
            'status' => ScanStatus::Cancelled,
            'finished_at' => now(),
            'summary' => $this->summary('cancelled', $issues),
        ]));
    }

    /**
     * @param  array<string, int>  $progress
     * @param  list<array{code: string, severity: string, message: string, path?: string, count?: int}>  $issues
     */
    private function flushProgress(
        ScanRun $scanRun,
        array $progress,
        array $issues,
        CarbonImmutable $seenAt,
    ): void {
        $this->flushPendingFileChanges($seenAt);
        $this->flushScanIssues();
        $scanRun->update(array_merge($progress, [
            'summary' => $this->summary('scanning', $issues),
        ]));
    }

    private function flushUnchangedFiles(CarbonImmutable $seenAt): void
    {
        if ($this->unchangedFileIds === []) {
            return;
        }

        MediaFile::query()
            ->whereIn('id', $this->unchangedFileIds)
            ->update(['last_seen_at' => $seenAt]);
        $this->unchangedFileIds = [];
    }

    private function flushPendingFileChanges(CarbonImmutable $seenAt): void
    {
        $this->flushUnchangedFiles($seenAt);
        $this->flushPlayStatisticsImports();
        $this->flushRatingImports();
    }

    private function deleteAlbumsWithoutMediaFiles(ScanRun $scanRun): void
    {
        Album::query()
            ->where('library_root_id', $scanRun->library_root_id)
            ->whereDoesntHave('mediaFiles')
            ->delete();
    }

    private function deleteOrphanedCatalogData(): void
    {
        Artist::query()
            ->whereDoesntHave('albums')
            ->whereDoesntHave('tracks')
            ->delete();
        Genre::query()
            ->whereDoesntHave('tracks')
            ->delete();
    }

    private function canRemoveStaleFiles(DiscoveryDiagnostics $diagnostics): bool
    {
        return $diagnostics->pathsRequiringPreservation() === [];
    }

    /** @param Builder<MediaFile> $staleFiles */
    private function markStaleFilesMissing(
        Builder $staleFiles,
        DiscoveryDiagnostics $diagnostics,
    ): int {
        $preservedPaths = $diagnostics->pathsRequiringPreservation();

        if ($preservedPaths === null) {
            return 0;
        }

        $deletableFiles = clone $staleFiles;
        $comparisonColumn = PHP_OS_FAMILY === 'Windows' ? 'LOWER(relative_path)' : 'relative_path';

        foreach ($preservedPaths as $path) {
            $comparisonPath = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($path) : $path;
            $deletableFiles
                ->whereRaw("{$comparisonColumn} <> ?", [$comparisonPath])
                ->whereRaw("NOT starts_with({$comparisonColumn}, ?)", [$comparisonPath.'/']);
        }

        $mediaFileIds = $deletableFiles
            ->where('status', '!=', MediaFileStatus::Missing->value)
            ->pluck('id');

        if ($mediaFileIds->isEmpty()) {
            return 0;
        }

        $trackIds = Track::query()
            ->whereIn('media_file_id', $mediaFileIds)
            ->pluck('id')
            ->map(fn (int $id): int => $id)
            ->all();
        $updated = MediaFile::query()
            ->whereIn('id', $mediaFileIds)
            ->update([
                'status' => MediaFileStatus::Missing,
                'scan_error' => null,
            ]);

        $this->playlistSynchronizationDispatcher->tracks($trackIds);

        return $updated;
    }

    private function prepareScanCaches(ScanRun $scanRun): void
    {
        $this->artworkSynced = [];
        $this->albumCache = [];
        $this->albumIdCache = [];
        $this->artistCache = [];
        $this->genreCache = [];
        $this->unchangedFileIds = [];
        $this->unchangedArtworkAlbums = [];
        $this->unchangedFilesFastTracked = 0;
        $this->discoveredPathHashes = [];
        $this->fingerprintsByPath = [];
        $this->fingerprintFailures = [];
        $this->newFingerprintCounts = [];
        $this->newFiles = [];
        $this->staleFilesByFingerprint = [];
        $this->issueRecordCount = 0;
        $this->pendingScanIssues = [];
        $this->pendingScanRunId = $scanRun->id;
        ScanRunIssue::query()->where('scan_run_id', $scanRun->id)->delete();
        $this->playStatisticsImported = 0;
        $this->pendingPlayStatisticsImports = [];
        $this->pendingPlayStatisticsImportMediaFileIds = [];
        $this->pendingRatingImportMediaFileIds = [];
        $settings = ApplicationSetting::current();
        $this->importPlayStatisticsFromTags = $settings->import_play_statistics_from_tags;
        $this->importRatingsFromTags = $settings->synchronizesRatingsWithTags();
        $this->ratingsImported = 0;
        $this->existingFiles = [];
        $query = $scanRun->libraryRoot->mediaFiles()
            ->getQuery()
            ->leftJoin('tracks', 'tracks.media_file_id', '=', 'media_files.id')
            ->leftJoin('albums', 'albums.id', '=', 'media_files.album_id')
            ->select([
                'media_files.id',
                'media_files.album_id',
                'albums.relative_path_hash as album_relative_path_hash',
                'media_files.relative_path_hash',
                'media_files.status',
                'media_files.file_size',
                'media_files.modified_at',
                'media_files.metadata_parser_version',
                'media_files.content_fingerprint',
                'media_files.content_fingerprint_version',
                'tracks.id as track_id',
                'media_files.rating_tags_import_version',
            ]);
        $this->scopeMediaFilesToScan($query, $scanRun, 'media_files.relative_path');

        foreach ($query->lazyById(1000, 'media_files.id', 'id') as $mediaFile) {
            $state = $this->mediaFileState($mediaFile);

            $this->existingFiles[$mediaFile->relative_path_hash] = $state;
            $state->trackId = $mediaFile->track_id === null
                ? null
                : (int) $mediaFile->track_id;
        }

        if ($this->importPlayStatisticsFromTags) {
            $this->preparePendingPlayStatisticsImports($scanRun);
        }
        if ($this->importRatingsFromTags) {
            $this->preparePendingRatingImports($scanRun);
        }
    }

    private function preparePendingPlayStatisticsImports(ScanRun $scanRun): void
    {
        $query = $scanRun->libraryRoot->mediaFiles()
            ->getQuery()
            ->leftJoin('tracks', 'tracks.media_file_id', '=', 'media_files.id')
            ->select([
                'media_files.id',
                'media_files.relative_path_hash',
                'media_files.raw_metadata',
                'tracks.id as track_id',
            ])
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('media_files.play_statistics_import_version')
                    ->orWhere(
                        'media_files.play_statistics_import_version',
                        '!=',
                        PlaybackStatisticsTagReader::IMPORT_VERSION,
                    );
            });
        $this->scopeMediaFilesToScan($query, $scanRun, 'media_files.relative_path');

        foreach ($query->lazyById(1000, 'media_files.id', 'id') as $mediaFile) {
            $pathHash = $mediaFile->relative_path_hash;

            if (! isset($this->existingFiles[$pathHash])) {
                continue;
            }

            $this->existingFiles[$pathHash]->trackId = $mediaFile->track_id === null
                ? null
                : (int) $mediaFile->track_id;
            $this->existingFiles[$pathHash]->playStatistics = $this->playStatisticsTagReader->read(
                $mediaFile->raw_metadata ?? [],
            );
        }
    }

    private function preparePendingRatingImports(ScanRun $scanRun): void
    {
        $query = $scanRun->libraryRoot->mediaFiles()
            ->getQuery()
            ->select([
                'media_files.id',
                'media_files.relative_path_hash',
                'media_files.raw_metadata',
            ])
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('media_files.rating_tags_import_version')
                    ->orWhere(
                        'media_files.rating_tags_import_version',
                        '!=',
                        RatingTagReader::IMPORT_VERSION,
                    );
            });
        $this->scopeMediaFilesToScan($query, $scanRun, 'media_files.relative_path');

        foreach ($query->lazyById(1000, 'media_files.id', 'id') as $mediaFile) {
            $state = $this->existingFiles[$mediaFile->relative_path_hash] ?? null;
            if ($state !== null) {
                $state->ratingTags = $this->ratingTagReader->read($mediaFile->raw_metadata ?? []);
            }
        }
    }

    private function mediaFileState(MediaFile $mediaFile): ScanMediaFileState
    {
        return new ScanMediaFileState(
            id: $mediaFile->id,
            albumId: $mediaFile->album_id,
            albumRelativePathHash: $mediaFile->album_relative_path_hash,
            status: $mediaFile->status,
            fileSize: $mediaFile->file_size,
            modifiedAt: $mediaFile->modified_at->getTimestamp(),
            metadataParserVersion: $mediaFile->metadata_parser_version,
            contentFingerprint: $mediaFile->content_fingerprint,
            contentFingerprintVersion: $mediaFile->content_fingerprint_version,
            ratingTagsImportVersion: $mediaFile->rating_tags_import_version,
        );
    }

    private function fingerprint(DiscoveredAudioFile $file): ?string
    {
        $pathHash = $this->pathHash($file->relativePath);
        if (array_key_exists($pathHash, $this->fingerprintsByPath)) {
            return $this->fingerprintsByPath[$pathHash];
        }

        try {
            return $this->fingerprintsByPath[$pathHash] = $this->contentFingerprinter->fingerprint($file->absolutePath);
        } catch (Throwable) {
            $this->fingerprintFailures[$file->relativePath] = true;

            return $this->fingerprintsByPath[$pathHash] = null;
        }
    }

    private function prepareMoveCandidates(): void
    {
        foreach ($this->existingFiles as $pathHash => $state) {
            $fingerprint = $state->contentFingerprint;
            if (isset($this->discoveredPathHashes[$pathHash])
                || $fingerprint === null
                || $state->contentFingerprintVersion !== AudioContentFingerprinter::VERSION) {
                continue;
            }

            $this->staleFilesByFingerprint[$fingerprint][] = [
                'pathHash' => $pathHash,
                'libraryRootId' => $this->libraryRootId(),
                'state' => $state,
            ];
        }
    }

    private function fingerprintNewFilesForMoveDetection(ScanRun $scanRun): bool
    {
        if ($this->newFiles === []) {
            $this->newFiles = [];

            return true;
        }

        foreach ($this->newFiles as $index => $file) {
            if ($index % self::CANCELLATION_CHECK_INTERVAL === 0
                && $this->cancellationRequested($scanRun)) {
                $this->newFiles = [];

                return false;
            }

            $fingerprint = $this->fingerprint($file);
            if ($fingerprint !== null) {
                $this->newFingerprintCounts[$fingerprint] = ($this->newFingerprintCounts[$fingerprint] ?? 0) + 1;
            }
        }

        $this->newFiles = [];

        return true;
    }

    private function reconcileMovedFile(
        ScanRun $scanRun,
        DiscoveredAudioFile $file,
        string $pathHash,
    ): ?ScanMediaFileState {
        $fingerprint = $this->fingerprint($file);
        if ($fingerprint === null
            || ($this->newFingerprintCounts[$fingerprint] ?? 0) !== 1) {
            return null;
        }

        $candidates = $this->staleFilesByFingerprint[$fingerprint] ?? [];
        $candidateIds = array_map(
            static fn (array $candidate): int => $candidate['state']->id,
            $candidates,
        );
        $globalCandidates = MediaFile::query()
            ->with([
                'track:id,media_file_id',
                'libraryRoot:id,path',
            ])
            ->whereIn('status', [
                MediaFileStatus::Available->value,
                MediaFileStatus::Missing->value,
            ])
            ->where('content_fingerprint', $fingerprint)
            ->where('content_fingerprint_version', AudioContentFingerprinter::VERSION)
            ->when(
                $candidateIds !== [],
                fn (Builder $query) => $query->whereNotIn('id', $candidateIds),
            )
            ->get([
                'id',
                'library_root_id',
                'album_id',
                'status',
                'file_size',
                'modified_at',
                'metadata_parser_version',
                'content_fingerprint',
                'content_fingerprint_version',
                'relative_path',
                'relative_path_hash',
            ])
            ->filter(fn (MediaFile $mediaFile): bool => $this->isMissingMoveCandidate($mediaFile));

        foreach ($globalCandidates as $mediaFile) {
            $state = $this->mediaFileState($mediaFile);
            $state->trackId = $mediaFile->track?->id;
            $candidates[] = [
                'pathHash' => $mediaFile->relative_path_hash,
                'libraryRootId' => $mediaFile->library_root_id,
                'state' => $state,
            ];
        }

        if (count($candidates) !== 1) {
            return null;
        }

        $candidate = $candidates[0];
        $updated = MediaFile::query()
            ->whereKey($candidate['state']->id)
            ->where('library_root_id', $candidate['libraryRootId'])
            ->where('relative_path_hash', $candidate['pathHash'])
            ->update([
                'library_root_id' => $scanRun->library_root_id,
                'relative_path' => $file->relativePath,
                'relative_path_hash' => $pathHash,
                'file_size' => $file->fileSize,
                'modified_at' => CarbonImmutable::createFromTimestamp($file->modifiedAt),
                'status' => MediaFileStatus::Missing,
                'content_fingerprint' => $fingerprint,
                'content_fingerprint_version' => AudioContentFingerprinter::VERSION,
            ]);

        if ($updated !== 1) {
            return null;
        }

        $state = $candidate['state'];
        $state->contentFingerprint = $fingerprint;
        $state->contentFingerprintVersion = AudioContentFingerprinter::VERSION;
        $state->moved = true;
        unset(
            $this->existingFiles[$candidate['pathHash']],
            $this->staleFilesByFingerprint[$fingerprint],
        );
        $this->existingFiles[$pathHash] = $state;
        if ($state->trackId !== null) {
            $this->playlistSynchronizationDispatcher->tracks([$state->trackId]);
        }

        return $state;
    }

    private function isMissingMoveCandidate(MediaFile $mediaFile): bool
    {
        if ($mediaFile->status === MediaFileStatus::Missing) {
            return true;
        }

        $root = $mediaFile->libraryRoot;
        if ($root === null) {
            return false;
        }

        try {
            return $this->pathGuard->resolveExistingFileWithin(
                $root->path,
                $mediaFile->relative_path,
            ) === null;
        } catch (InvalidLibraryPath) {
            return false;
        }
    }

    /** @return list<string> */
    private function importPlayStatistics(
        Track|int|null $track,
        ImportedPlayStatistics $imported,
        int $mediaFileId,
    ): array {
        if (! $this->importPlayStatisticsFromTags) {
            return [];
        }

        $this->pendingPlayStatisticsImportMediaFileIds[$mediaFileId] = true;
        $trackId = $track instanceof Track ? $track->id : $track;

        if ($trackId !== null && $imported->hasValues()) {
            $this->pendingPlayStatisticsImports[$trackId] = [
                'trackId' => $trackId,
                'statistics' => $imported,
            ];
        }

        return $imported->warnings;
    }

    private function flushPlayStatisticsImports(): void
    {
        if ($this->pendingPlayStatisticsImports === []
            && $this->pendingPlayStatisticsImportMediaFileIds === []) {
            return;
        }

        if ($this->pendingPlayStatisticsImports !== []) {
            $this->playStatisticsImported += $this->playStatisticsImporter->mergeMany(
                array_values($this->pendingPlayStatisticsImports),
            );
        }

        if ($this->pendingPlayStatisticsImportMediaFileIds !== []) {
            MediaFile::query()
                ->whereKey(array_keys($this->pendingPlayStatisticsImportMediaFileIds))
                ->update([
                    'play_statistics_import_version' => PlaybackStatisticsTagReader::IMPORT_VERSION,
                ]);
        }

        $this->pendingPlayStatisticsImports = [];
        $this->pendingPlayStatisticsImportMediaFileIds = [];
    }

    private function previousRatingTags(?ScanMediaFileState $existing): ?ImportedRatingTags
    {
        if (! $this->importRatingsFromTags || $existing === null) {
            return null;
        }

        $mediaFile = MediaFile::find($existing->id);

        return $mediaFile === null ? null : $this->ratingTagReader->read($mediaFile->raw_metadata ?? []);
    }

    private function tagChanged(
        ?ImportedRatingTags $previous,
        ImportedRatingTags $current,
        string $field,
    ): bool {
        if ($previous === null) {
            return false;
        }

        return match ($field) {
            'track' => $previous->trackTagPresent !== $current->trackTagPresent
                || $previous->trackHalfSteps !== $current->trackHalfSteps,
            'album' => $previous->albumTagPresent !== $current->albumTagPresent
                || $previous->albumHalfSteps !== $current->albumHalfSteps,
            default => throw new \LogicException("Unknown rating field [{$field}]."),
        };
    }

    /** @return list<string> */
    private function importRatings(
        Track|int|null $track,
        ?int $albumId,
        ImportedRatingTags $imported,
        int $mediaFileId,
        bool $overwriteTrack = false,
        bool $overwriteAlbum = false,
    ): array {
        if (! $this->importRatingsFromTags) {
            return [];
        }

        $this->pendingRatingImportMediaFileIds[$mediaFileId] = true;
        $trackModel = $track instanceof Track ? $track : ($track === null ? null : Track::find($track));
        if ($trackModel !== null
            && ($overwriteTrack || ($imported->trackTagPresent && $trackModel->rating_half_steps === null))) {
            $trackModel->rating_half_steps = $imported->trackTagPresent
                ? $imported->trackHalfSteps
                : null;
            if ($trackModel->isDirty('rating_half_steps')) {
                $trackModel->save();
                $this->ratingsImported++;
            }
        }

        $album = $albumId === null ? null : Album::find($albumId);
        if ($album !== null
            && ($overwriteAlbum || ($imported->albumTagPresent && $album->rating_half_steps === null))) {
            $album->rating_half_steps = $imported->albumTagPresent
                ? $imported->albumHalfSteps
                : null;
            if ($album->isDirty('rating_half_steps')) {
                $album->save();
                $this->ratingsImported++;
                $this->albumIdCache[$album->id] = $album;
            }
        }

        return $imported->warnings;
    }

    private function flushRatingImports(): void
    {
        if ($this->pendingRatingImportMediaFileIds === []) {
            return;
        }

        MediaFile::query()
            ->whereKey(array_keys($this->pendingRatingImportMediaFileIds))
            ->update(['rating_tags_import_version' => RatingTagReader::IMPORT_VERSION]);
        $this->pendingRatingImportMediaFileIds = [];
    }

    private function findAlbumById(?int $albumId): ?Album
    {
        if ($albumId === null) {
            return null;
        }

        return $this->albumIdCache[$albumId] ??= Album::with('artwork')->find($albumId);
    }

    private function albumForFile(
        ScanRun $scanRun,
        ?ScanMediaFileState $existing,
        string $albumHash,
    ): Album {
        $existingAlbum = $existing === null ? null : $this->findAlbumById($existing->albumId);

        if ($existingAlbum !== null
            && ($existingAlbum->library_root_id !== $scanRun->library_root_id
                || $existingAlbum->relative_path_hash !== $albumHash)
            && ! Album::query()
                ->where('library_root_id', $scanRun->library_root_id)
                ->where('relative_path_hash', $albumHash)
                ->exists()
            && ($existingAlbum->library_root_id === $scanRun->library_root_id
                || ! $existingAlbum->mediaFiles()
                    ->where('status', '!=', MediaFileStatus::Missing->value)
                    ->exists())) {
            $existingAlbum->library_root_id = $scanRun->library_root_id;

            return $existingAlbum;
        }

        return Album::firstOrNew([
            'library_root_id' => $scanRun->library_root_id,
            'relative_path_hash' => $albumHash,
        ]);
    }

    private function resetModelCaches(): void
    {
        $this->albumCache = [];
        $this->albumIdCache = [];
        $this->artistCache = [];
        $this->genreCache = [];
    }

    /**
     * @param  list<array{code: string, severity: string, message: string, path?: string, count?: int}>  $issues
     */
    private function addIssue(
        array &$issues,
        string $code,
        string $severity,
        string $message,
        ?string $path = null,
        int $count = 1,
    ): void {
        $message = mb_convert_encoding(str_replace("\0", '', $message), 'UTF-8', 'UTF-8');
        $path = $path === null
            ? null
            : mb_convert_encoding(str_replace("\0", '', $path), 'UTF-8', 'UTF-8');
        $issue = compact('code', 'severity', 'message', 'count');

        if ($path !== null && $path !== '') {
            $issue['path'] = $path;
        }

        if (count($issues) < self::MAX_REPORTED_ISSUES) {
            $issues[] = $issue;
        }

        $timestamp = now();
        $this->pendingScanIssues[] = [
            'scan_run_id' => $this->scanRunId(),
            'code' => mb_substr($code, 0, 64),
            'severity' => mb_substr($severity, 0, 16),
            'message' => $message,
            'path' => $path,
            'occurrence_count' => max(1, $count),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
        $this->issueRecordCount++;

        if (count($this->pendingScanIssues) >= self::ISSUE_FLUSH_INTERVAL) {
            $this->flushScanIssues();
        }
    }

    private function flushScanIssues(): void
    {
        if ($this->pendingScanIssues === []) {
            return;
        }

        ScanRunIssue::query()->insert($this->pendingScanIssues);
        $this->activityLogger->scanIssues(
            $this->libraryRootId(),
            $this->pendingScanIssues,
        );
        $this->pendingScanIssues = [];
    }

    private function scanRunId(): int
    {
        if ($this->pendingScanRunId === null) {
            throw new \LogicException('A scan issue cannot be recorded before a scan has started.');
        }

        return $this->pendingScanRunId;
    }

    private function libraryRootId(): int
    {
        if ($this->pendingLibraryRootId === null) {
            throw new \LogicException('A scan issue cannot be recorded before a library root is known.');
        }

        return $this->pendingLibraryRootId;
    }

    /**
     * @param  list<array{code: string, severity: string, message: string, path?: string, count?: int}>  $issues
     * @return array<string, mixed>
     */
    private function summary(string $phase, array $issues, ?string $error = null): array
    {
        return array_filter([
            'phase' => $phase,
            'subtreePath' => $this->subtreePath,
            'scanPaths' => $this->scanPaths,
            'missingPaths' => $this->missingPaths,
            'unchangedFilesFastTracked' => $this->unchangedFilesFastTracked ?: null,
            'error' => $error,
            'playStatisticsImported' => $this->playStatisticsImported ?: null,
            'ratingsImported' => $this->ratingsImported ?: null,
            'issuesTruncated' => $this->issueRecordCount > count($issues) ?: null,
            'issues' => $issues,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function findOrCreateArtist(string $name): Artist
    {
        $name = $this->limited(trim($name) ?: 'Unknown Artist', 512);
        $cacheKey = mb_strtolower($name);

        if (isset($this->artistCache[$cacheKey])) {
            return $this->artistCache[$cacheKey];
        }

        $artist = Artist::query()->whereRaw('LOWER(name) = LOWER(?)', [$name])->first();

        return $this->artistCache[$cacheKey] = $artist ?? Artist::create([
            'name' => $name,
            'sort_name' => $name,
            'browse_initial' => $this->artistName->browseInitial($name),
        ]);
    }

    /** @return iterable<int, DiscoveredAudioFile> */
    private function discover(ScanRun $scanRun, DiscoveryDiagnostics $diagnostics): iterable
    {
        if ($this->isDeltaScan()) {
            foreach ($this->scanPaths ?? [] as $scanPath) {
                yield from $this->discoverer->discover(
                    $scanRun->libraryRoot,
                    $diagnostics,
                    $scanPath === '' ? null : $scanPath,
                );
            }

            return;
        }

        if ($scanRun->subtree_path === null) {
            yield from $this->discoverer->discover($scanRun->libraryRoot, $diagnostics);

            return;
        }

        yield from $this->discoverer->discover(
            $scanRun->libraryRoot,
            $diagnostics,
            $scanRun->subtree_path,
        );
    }

    /** @param Builder<MediaFile> $query */
    private function scopeMediaFilesToScan(
        Builder $query,
        ScanRun $scanRun,
        string $column = 'relative_path',
    ): void {
        if ($this->isDeltaScan()) {
            $paths = array_values(array_unique([
                ...($this->scanPaths ?? []),
                ...($this->missingPaths ?? []),
            ]));

            if (in_array('', $paths, true)) {
                return;
            }

            if ($paths === []) {
                $query->whereRaw('1 = 0');

                return;
            }

            $comparisonColumn = PHP_OS_FAMILY === 'Windows' ? "LOWER({$column})" : $column;
            $comparisonPaths = array_map(
                static fn (string $path): string => PHP_OS_FAMILY === 'Windows'
                    ? mb_strtolower($path)
                    : $path,
                $paths,
            );

            $query->where(function (Builder $query) use ($comparisonColumn, $comparisonPaths): void {
                foreach ($comparisonPaths as $path) {
                    $query->orWhere(function (Builder $query) use ($comparisonColumn, $path): void {
                        $query
                            ->whereRaw("{$comparisonColumn} = ?", [$path])
                            ->orWhereRaw("starts_with({$comparisonColumn}, ?)", [$path.'/']);
                    });
                }
            });

            return;
        }

        if ($scanRun->subtree_path === null) {
            return;
        }

        $subtreePath = PHP_OS_FAMILY === 'Windows'
            ? mb_strtolower($scanRun->subtree_path)
            : $scanRun->subtree_path;
        $comparisonColumn = PHP_OS_FAMILY === 'Windows' ? "LOWER({$column})" : $column;

        $query->where(function (Builder $query) use ($comparisonColumn, $subtreePath): void {
            $query
                ->whereRaw("{$comparisonColumn} = ?", [$subtreePath])
                ->orWhereRaw("starts_with({$comparisonColumn}, ?)", [$subtreePath.'/']);
        });
    }

    private function isDeltaScan(): bool
    {
        return $this->scanPaths !== null || $this->missingPaths !== null;
    }

    private function findOrCreateGenre(string $name): Genre
    {
        $name = $this->limited(trim($name), 255);
        $cacheKey = mb_strtolower($name);

        if (isset($this->genreCache[$cacheKey])) {
            return $this->genreCache[$cacheKey];
        }

        return $this->genreCache[$cacheKey] = $this->genreResolver->resolve($name);
    }

    private function pathHash(string $relativePath): string
    {
        return hash('sha256', mb_strtolower(str_replace('\\', '/', $relativePath)));
    }

    private function limited(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }

    private function exceptionMessage(Throwable $exception, int $limit = 1000): string
    {
        $message = str_replace("\0", '', $exception->getMessage());

        if (str_contains($message, ' (Connection:')) {
            $message = strstr($message, ' (Connection:', before_needle: true) ?: $message;
        }

        return $this->limited($message, $limit);
    }
}
