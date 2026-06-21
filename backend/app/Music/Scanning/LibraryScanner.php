<?php

namespace App\Music\Scanning;

use App\Enums\MediaFileStatus;
use App\Enums\ScanStatus;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\MediaFile;
use App\Models\ScanRun;
use App\Models\Track;
use App\Music\Artwork\AlbumArtworkManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class LibraryScanner
{
    private const CANCELLATION_CHECK_INTERVAL = 10;

    private const DISCOVERY_UPDATE_INTERVAL = 250;

    private const MODEL_CACHE_RESET_INTERVAL = 250;

    private const MAX_REPORTED_ISSUES = 50;

    private const PROGRESS_UPDATE_INTERVAL = 25;

    /** @var array<int, true> */
    private array $artworkSynced = [];

    /** @var array<string, Album> */
    private array $albumCache = [];

    /** @var array<int, Album> */
    private array $albumIdCache = [];

    /** @var array<string, Artist> */
    private array $artistCache = [];

    /** @var array<string, array{id: int, album_id: ?int, status: MediaFileStatus, file_size: int, modified_at: int}> */
    private array $existingFiles = [];

    /** @var array<string, Genre> */
    private array $genreCache = [];

    /** @var list<int> */
    private array $unchangedFileIds = [];

    public function __construct(
        private readonly AudioFileDiscoverer $discoverer,
        private readonly AudioMetadataReader $metadataReader,
        private readonly ArtistName $artistName,
        private readonly AlbumArtworkManager $artworkManager,
    ) {}

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
            $countDiagnostics = new DiscoveryDiagnostics;

            foreach ($this->discoverer->discover($scanRun->libraryRoot, $countDiagnostics) as $_file) {
                $progress['files_discovered']++;

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
            $diagnostics = new DiscoveryDiagnostics;

            foreach ($this->discoverer->discover($scanRun->libraryRoot, $diagnostics) as $file) {
                if ($progress['files_processed'] % self::CANCELLATION_CHECK_INTERVAL === 0
                    && $this->cancellationRequested($scanRun)) {
                    $this->flushUnchangedFiles($startedAt);
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

            if ($progress['files_discovered'] === 0 && $existingFileCount > 0) {
                $message = 'No supported audio files were found, although this root previously contained indexed files. Existing file availability was preserved.';
                $this->addIssue($issues, 'empty_scan_preserved', 'error', $message);
                $progress['error_count']++;
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

            $missing = $scanRun->libraryRoot->mediaFiles()
                ->where('last_seen_at', '<', $startedAt)
                ->where('status', '!=', MediaFileStatus::Missing->value)
                ->update(['status' => MediaFileStatus::Missing->value]);

            if ($missing > 0) {
                $this->addIssue(
                    $issues,
                    'files_missing',
                    'warning',
                    'Previously indexed audio files were not found during this scan.',
                    count: $missing,
                );
                $progress['warning_count'] += $missing;
            }

            $this->deleteAlbumsWithoutMediaFiles($scanRun);

            $scanRun->libraryRoot->update(['last_scanned_at' => now()]);
            $scanRun->update(array_merge($progress, [
                'status' => ScanStatus::Completed,
                'files_missing' => $missing,
                'finished_at' => now(),
                'summary' => $this->summary('completed', $issues),
            ]));
        } catch (Throwable $exception) {
            $this->flushUnchangedFiles($startedAt);
            $this->addIssue($issues, 'scan_failed', 'error', $exception->getMessage());
            $progress['error_count']++;
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

        if ($existing !== null
            && $existing['status'] === MediaFileStatus::Available
            && $existing['file_size'] === $file->fileSize
            && $existing['modified_at'] === $file->modifiedAt) {
            $album = $this->findAlbumById($existing['album_id']);

            if ($album !== null && $album->relative_path_hash === $this->pathHash($file->albumRelativePath)) {
                $this->unchangedFileIds[] = $existing['id'];
                $warnings = $this->syncArtwork($album, $scanRun, audioPath: $file->absolutePath);

                return ['created' => false, 'changed' => false, 'warnings' => $warnings];
            }
        }

        $metadata = $this->metadataReader->read($file->absolutePath);

        try {
            $processed = DB::transaction(function () use ($scanRun, $file, $seenAt, $pathHash, $existing, $metadata): array {
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
                    'created' => $existing === null,
                ];
            });
        } catch (Throwable $exception) {
            $this->resetModelCaches();

            throw $exception;
        }

        $this->albumIdCache[$processed['album']->id] = $processed['album'];

        return [
            'created' => $processed['created'],
            'changed' => true,
            'warnings' => array_merge(
                $metadata->warnings,
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

    private function prepareScanCaches(ScanRun $scanRun): void
    {
        $this->artworkSynced = [];
        $this->albumCache = [];
        $this->albumIdCache = [];
        $this->artistCache = [];
        $this->genreCache = [];
        $this->unchangedFileIds = [];
        $this->existingFiles = $scanRun->libraryRoot
            ->mediaFiles()
            ->get(['id', 'album_id', 'relative_path_hash', 'status', 'file_size', 'modified_at'])
            ->mapWithKeys(fn (MediaFile $mediaFile): array => [
                $mediaFile->relative_path_hash => $this->compactFileState($mediaFile),
            ])
            ->all();
    }

    /** @return array{id: int, album_id: ?int, status: MediaFileStatus, file_size: int, modified_at: int} */
    private function compactFileState(MediaFile $mediaFile): array
    {
        return [
            'id' => $mediaFile->id,
            'album_id' => $mediaFile->album_id,
            'status' => $mediaFile->status,
            'file_size' => $mediaFile->file_size,
            'modified_at' => $mediaFile->modified_at->getTimestamp(),
        ];
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
        if (count($issues) >= self::MAX_REPORTED_ISSUES) {
            return;
        }

        $issue = compact('code', 'severity', 'message', 'count');

        if ($path !== null && $path !== '') {
            $issue['path'] = $path;
        }

        $issues[] = $issue;
    }

    /**
     * @param  list<array{code: string, severity: string, message: string, path?: string, count?: int}>  $issues
     * @return array<string, mixed>
     */
    private function summary(string $phase, array $issues, ?string $error = null): array
    {
        return array_filter([
            'phase' => $phase,
            'error' => $error,
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
