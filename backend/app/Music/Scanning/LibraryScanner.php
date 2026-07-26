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
use App\Music\PlaybackStatistics\ImportedPlayStatistics;
use App\Music\PlaybackStatistics\PlaybackStatisticsImporter;
use App\Music\PlaybackStatistics\PlaybackStatisticsTagReader;
use App\Music\Playlists\PlaylistFileSynchronizationDispatcher;
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

    /** @var array<int, true> */
    private array $artworkSynced = [];

    /** @var array<string, Album> */
    private array $albumCache = [];

    /** @var array<int, Album> */
    private array $albumIdCache = [];

    /** @var array<string, Artist> */
    private array $artistCache = [];

    /** @var array<string, array{id: int, album_id: ?int, status: MediaFileStatus, file_size: int, modified_at: int, metadata_parser_version: int, content_fingerprint: ?string, content_fingerprint_version: ?int, moved?: bool, track_id?: ?int, play_statistics?: ImportedPlayStatistics}> */
    private array $existingFiles = [];

    /** @var array<string, ?string> */
    private array $fingerprintsByPath = [];

    /** @var array<string, true> */
    private array $fingerprintFailures = [];

    /** @var array<string, int> */
    private array $newFingerprintCounts = [];

    /** @var list<DiscoveredAudioFile> */
    private array $newFiles = [];

    /** @var array<string, list<array{path_hash: string, state: array{id: int, album_id: ?int, status: MediaFileStatus, file_size: int, modified_at: int, metadata_parser_version: int, content_fingerprint: ?string, content_fingerprint_version: ?int, moved?: bool, track_id?: ?int, play_statistics?: ImportedPlayStatistics>}>> */
    private array $staleFilesByFingerprint = [];

    /** @var array<string, true> */
    private array $discoveredPathHashes = [];

    /** @var array<string, Genre> */
    private array $genreCache = [];

    /** @var list<int> */
    private array $unchangedFileIds = [];

    private bool $importPlayStatisticsFromTags = false;

    private int $playStatisticsImported = 0;

    private int $issueRecordCount = 0;

    private bool $storedFingerprintsAvailable = false;

    /** @var list<array{scan_run_id: int, code: string, severity: string, message: string, path: ?string, occurrence_count: int, created_at: mixed, updated_at: mixed}> */
    private array $pendingScanIssues = [];

    private ?int $pendingScanRunId = null;

    private ?string $subtreePath = null;

    /** @var array<int, array{trackId: int, statistics: ImportedPlayStatistics}> */
    private array $pendingPlayStatisticsImports = [];

    public function __construct(
        private readonly AudioFileDiscoverer $discoverer,
        private readonly AudioMetadataReader $metadataReader,
        private readonly AudioContentFingerprinter $contentFingerprinter,
        private readonly ArtistName $artistName,
        private readonly AlbumArtworkManager $artworkManager,
        private readonly PlaybackStatisticsTagReader $playStatisticsTagReader,
        private readonly PlaybackStatisticsImporter $playStatisticsImporter,
        private readonly PlaylistFileSynchronizationDispatcher $playlistSynchronizationDispatcher,
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
        $this->subtreePath = $scanRun->subtree_path;
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
            $countDiagnostics = new DiscoveryDiagnostics();

            foreach ($this->discover($scanRun, $countDiagnostics) as $file) {
                $progress['files_discovered']++;
                $pathHash = $this->pathHash($file->relativePath);
                $this->discoveredPathHashes[$pathHash] = true;

                if (! isset($this->existingFiles[$pathHash]) && $this->hasStoredFingerprints()) {
                    $this->newFiles[] = $file;
                }

                if ($progress['files_discovered'] % self::DISCOVERY_UPDATE_INTERVAL === 0
                    && $this->cancellationRequested($scanRun)) {
                    $this->cancelScan($scanRun, $progress, $issues);

                    return;
                }

                if ($progress['files_discovered'] % self::DISCOVERY_UPDATE_INTERVAL === 0) {
                    $scanRun->update(array_merge($progress, [
                        'summary' => $this->summary('counting', $issues),
                    ]));
                }
            }

            $scanRun->update(array_merge($progress, [
                'summary' => $this->summary('scanning', $issues),
            ]));
            $this->prepareMoveCandidates();
            if (! $this->fingerprintNewFilesForMoveDetection($scanRun)) {
                $this->cancelScan($scanRun, $progress, $issues);

                return;
            }
            $diagnostics = new DiscoveryDiagnostics();

            foreach ($this->discover($scanRun, $diagnostics) as $file) {
                if ($progress['files_processed'] % self::CANCELLATION_CHECK_INTERVAL === 0
                    && $this->cancellationRequested($scanRun)) {
                    $this->flushUnchangedFiles($startedAt);
                    $this->flushPlayStatisticsImports();
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

            $this->flushUnchangedFiles($startedAt);
            $this->flushPlayStatisticsImports();

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

            if ($progress['files_discovered'] === 0) {
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
            $removed = $this->deleteStaleFiles($staleFiles, $diagnostics);

            if ($removed > 0) {
                $this->addIssue(
                    $issues,
                    'files_removed',
                    'warning',
                    'Previously indexed audio files were not found and were removed from the catalog.',
                    count: $removed,
                );
                $progress['warning_count'] += $removed;
            }

            if (! $canRemoveStaleFiles && (clone $staleFiles)->exists()) {
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

            if ($scanRun->subtree_path === null) {
                $scanRun->libraryRoot->update(['last_scanned_at' => now()]);
            }
            $scanRun->update(array_merge($progress, [
                'status' => ScanStatus::Completed,
                'files_removed' => $removed,
                'finished_at' => now(),
                'summary' => $this->summary('completed', $issues),
            ]));
        } catch (Throwable $exception) {
            $this->flushUnchangedFiles($startedAt);
            $this->flushPlayStatisticsImports();
            $this->addIssue($issues, 'scan_failed', 'error', $exception->getMessage());
            $progress['error_count']++;
            $this->flushScanIssues();
            $scanRun->update(array_merge($progress, [
                'status' => ScanStatus::Failed,
                'finished_at' => now(),
                'summary' => $this->summary('failed', $issues, $exception->getMessage()),
            ]));

            throw $exception;
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

        if ($existing !== null
            && ! ($existing['moved'] ?? false)
            && $existing['status'] === MediaFileStatus::Available
            && $existing['file_size'] === $file->fileSize
            && $existing['modified_at'] === $file->modifiedAt
            && $existing['metadata_parser_version'] === self::METADATA_PARSER_VERSION) {
            $album = $this->findAlbumById($existing['album_id']);

            if ($album !== null && $album->relative_path_hash === $this->pathHash($file->albumRelativePath)) {
                if ($existing['content_fingerprint'] === null
                    || $existing['content_fingerprint_version'] !== AudioContentFingerprinter::VERSION) {
                    MediaFile::query()->whereKey($existing['id'])->update([
                        'content_fingerprint' => $this->fingerprint($file),
                        'content_fingerprint_version' => AudioContentFingerprinter::VERSION,
                    ]);
                }

                $this->unchangedFileIds[] = $existing['id'];
                $warnings = $this->syncArtwork($album, $scanRun, audioPath: $file->absolutePath);
                $trackId = $existing['track_id'] ?? null;
                $imported = $existing['play_statistics'] ?? null;
                if ($trackId !== null && $imported !== null) {
                    $warnings = array_merge($warnings, $this->importPlayStatistics(Track::find($trackId), $imported));
                }

                return ['created' => false, 'changed' => false, 'warnings' => $warnings];
            }
        }

        $contentFingerprint = $this->fingerprint($file);
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
        );

        return [
            'created' => $processed['created'],
            'changed' => true,
            'warnings' => array_merge(
                $metadata->warnings,
                $importWarnings,
                $this->syncArtwork(
                    $processed['album'],
                    $scanRun,
                    metadata: $metadata,
                    embeddedInspected: true,
                ),
            ),
        ];
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
        $this->flushUnchangedFiles($seenAt);
        $this->flushPlayStatisticsImports();
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
    private function deleteStaleFiles(Builder $staleFiles, DiscoveryDiagnostics $diagnostics): int
    {
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

        return $deletableFiles->delete();
    }

    private function prepareScanCaches(ScanRun $scanRun): void
    {
        $this->artworkSynced = [];
        $this->albumCache = [];
        $this->albumIdCache = [];
        $this->artistCache = [];
        $this->genreCache = [];
        $this->unchangedFileIds = [];
        $this->discoveredPathHashes = [];
        $this->fingerprintsByPath = [];
        $this->fingerprintFailures = [];
        $this->newFingerprintCounts = [];
        $this->newFiles = [];
        $this->staleFilesByFingerprint = [];
        $this->storedFingerprintsAvailable = false;
        $this->issueRecordCount = 0;
        $this->pendingScanIssues = [];
        $this->pendingScanRunId = $scanRun->id;
        ScanRunIssue::query()->where('scan_run_id', $scanRun->id)->delete();
        $this->playStatisticsImported = 0;
        $this->pendingPlayStatisticsImports = [];
        $this->importPlayStatisticsFromTags = ApplicationSetting::current()->import_play_statistics_from_tags;
        $this->existingFiles = [];
        $query = $scanRun->libraryRoot->mediaFiles()
            ->getQuery()
            ->select([
                'media_files.id',
                'media_files.album_id',
                'media_files.relative_path_hash',
                'media_files.status',
                'media_files.file_size',
                'media_files.modified_at',
                'media_files.metadata_parser_version',
                'media_files.content_fingerprint',
                'media_files.content_fingerprint_version',
            ]);
        $this->scopeMediaFilesToScan($query, $scanRun, 'media_files.relative_path');

        if ($this->importPlayStatisticsFromTags) {
            $query->leftJoin('tracks', 'tracks.media_file_id', '=', 'media_files.id')
                ->addSelect(['media_files.raw_metadata', 'tracks.id as track_id']);
        }

        foreach ($query->lazyById(250, 'media_files.id', 'id') as $mediaFile) {
            $state = $this->compactFileState($mediaFile);
            if ($this->importPlayStatisticsFromTags) {
                $state['track_id'] = $mediaFile->track_id === null ? null : (int) $mediaFile->track_id;
                $state['play_statistics'] = $this->playStatisticsTagReader->read($mediaFile->raw_metadata ?? []);
            }

            if ($state['content_fingerprint'] !== null
                && $state['content_fingerprint_version'] === AudioContentFingerprinter::VERSION) {
                $this->storedFingerprintsAvailable = true;
            }

            $this->existingFiles[$mediaFile->relative_path_hash] = $state;
        }
    }

    /** @return array{id: int, album_id: ?int, status: MediaFileStatus, file_size: int, modified_at: int, metadata_parser_version: int, content_fingerprint: ?string, content_fingerprint_version: ?int} */
    private function compactFileState(MediaFile $mediaFile): array
    {
        return [
            'id' => $mediaFile->id,
            'album_id' => $mediaFile->album_id,
            'status' => $mediaFile->status,
            'file_size' => $mediaFile->file_size,
            'modified_at' => $mediaFile->modified_at->getTimestamp(),
            'metadata_parser_version' => $mediaFile->metadata_parser_version,
            'content_fingerprint' => $mediaFile->content_fingerprint,
            'content_fingerprint_version' => $mediaFile->content_fingerprint_version,
        ];
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
            $fingerprint = $state['content_fingerprint'];
            if (isset($this->discoveredPathHashes[$pathHash])
                || $fingerprint === null
                || $state['content_fingerprint_version'] !== AudioContentFingerprinter::VERSION) {
                continue;
            }

            $this->staleFilesByFingerprint[$fingerprint][] = [
                'path_hash' => $pathHash,
                'state' => $state,
            ];
        }
    }

    private function fingerprintNewFilesForMoveDetection(ScanRun $scanRun): bool
    {
        if ($this->staleFilesByFingerprint === []) {
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

    private function hasStoredFingerprints(): bool
    {
        return $this->storedFingerprintsAvailable;
    }

    /** @return array{id: int, album_id: ?int, status: MediaFileStatus, file_size: int, modified_at: int, metadata_parser_version: int, content_fingerprint: ?string, content_fingerprint_version: ?int, moved?: bool, track_id?: ?int, play_statistics?: ImportedPlayStatistics}|null */
    private function reconcileMovedFile(
        ScanRun $scanRun,
        DiscoveredAudioFile $file,
        string $pathHash,
    ): ?array {
        $fingerprint = $this->fingerprint($file);
        if ($fingerprint === null
            || ($this->newFingerprintCounts[$fingerprint] ?? 0) !== 1
            || count($this->staleFilesByFingerprint[$fingerprint] ?? []) !== 1) {
            return null;
        }

        $candidate = $this->staleFilesByFingerprint[$fingerprint][0];
        $updated = MediaFile::query()
            ->whereKey($candidate['state']['id'])
            ->where('library_root_id', $scanRun->library_root_id)
            ->where('relative_path_hash', $candidate['path_hash'])
            ->update([
                'relative_path' => $file->relativePath,
                'relative_path_hash' => $pathHash,
                'file_size' => $file->fileSize,
                'modified_at' => CarbonImmutable::createFromTimestamp($file->modifiedAt),
                'content_fingerprint' => $fingerprint,
                'content_fingerprint_version' => AudioContentFingerprinter::VERSION,
            ]);

        if ($updated !== 1) {
            return null;
        }

        $state = $candidate['state'];
        $state['content_fingerprint'] = $fingerprint;
        $state['content_fingerprint_version'] = AudioContentFingerprinter::VERSION;
        $state['moved'] = true;
        unset(
            $this->existingFiles[$candidate['path_hash']],
            $this->staleFilesByFingerprint[$fingerprint],
        );
        $this->existingFiles[$pathHash] = $state;
        if (($state['track_id'] ?? null) !== null) {
            $this->playlistSynchronizationDispatcher->tracks([$state['track_id']]);
        }

        return $state;
    }

    /** @return list<string> */
    private function importPlayStatistics(?Track $track, ImportedPlayStatistics $imported): array
    {
        if (! $this->importPlayStatisticsFromTags) {
            return [];
        }

        if ($track !== null && $imported->hasValues()) {
            $this->pendingPlayStatisticsImports[$track->id] = [
                'trackId' => $track->id,
                'statistics' => $imported,
            ];
        }

        return $imported->warnings;
    }

    private function flushPlayStatisticsImports(): void
    {
        if ($this->pendingPlayStatisticsImports === []) {
            return;
        }

        $this->playStatisticsImported += $this->playStatisticsImporter->mergeMany(
            array_values($this->pendingPlayStatisticsImports),
        );
        $this->pendingPlayStatisticsImports = [];
    }

    private function findAlbumById(?int $albumId): ?Album
    {
        if ($albumId === null) {
            return null;
        }

        return $this->albumIdCache[$albumId] ??= Album::with('artwork')->find($albumId);
    }

    private function albumForFile(ScanRun $scanRun, ?array $existing, string $albumHash): Album
    {
        $existingAlbum = $existing === null ? null : $this->findAlbumById($existing['album_id']);

        if ($existingAlbum !== null
            && $existingAlbum->library_root_id === $scanRun->library_root_id
            && $existingAlbum->relative_path_hash !== $albumHash
            && ! Album::query()
                ->where('library_root_id', $scanRun->library_root_id)
                ->where('relative_path_hash', $albumHash)
                ->exists()) {
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
        $this->pendingScanIssues = [];
    }

    private function scanRunId(): int
    {
        if ($this->pendingScanRunId === null) {
            throw new \LogicException('A scan issue cannot be recorded before a scan has started.');
        }

        return $this->pendingScanRunId;
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
            'error' => $error,
            'playStatisticsImported' => $this->playStatisticsImported ?: null,
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
        if ($scanRun->subtree_path === null) {
            return $this->discoverer->discover($scanRun->libraryRoot, $diagnostics);
        }

        return $this->discoverer->discover(
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


    private function findOrCreateGenre(string $name): Genre
    {
        $name = $this->limited(trim($name), 255);
        $cacheKey = mb_strtolower($name);

        if (isset($this->genreCache[$cacheKey])) {
            return $this->genreCache[$cacheKey];
        }

        $genre = Genre::query()->whereRaw('LOWER(name) = LOWER(?)', [$name])->first();

        return $this->genreCache[$cacheKey] = $genre ?? Genre::create(['name' => $name]);
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
