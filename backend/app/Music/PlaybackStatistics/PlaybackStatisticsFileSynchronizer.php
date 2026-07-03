<?php

namespace App\Music\PlaybackStatistics;

use App\Models\Track;
use App\Models\TrackPlayStatistic;
use App\Music\Scanning\AudioMetadataReader;
use App\Music\Scanning\LibraryPathGuard;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

class PlaybackStatisticsFileSynchronizer
{
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly PlaybackStatisticsTagWriter $writer,
        private readonly PlaybackStatisticsTagReader $reader,
        private readonly AudioMetadataReader $metadataReader,
    ) {
    }

    public function synchronize(Track $track): void
    {
        $track->loadMissing(['mediaFile.libraryRoot', 'playStatistic']);
        $mediaFile = $track->mediaFile;
        $statistics = $track->playStatistic;
        if ($mediaFile === null || $statistics === null) {
            return;
        }

        try {
            $path = $this->pathGuard->resolveExistingFileWithin(
                $mediaFile->libraryRoot->path,
                $mediaFile->relative_path,
            );
            if ($path === null) {
                throw new RuntimeException('The audio file no longer exists.');
            }
            if (! $this->writer->supports($path)) {
                throw new UnsupportedPlaybackStatisticsTagFormat(
                    sprintf('Playback-statistics export is not supported for .%s files.', pathinfo($path, PATHINFO_EXTENSION)),
                );
            }

            $this->writer->write(
                $path,
                $statistics->play_count,
                $statistics->first_played_at,
                $statistics->last_played_at,
            );
            $metadata = $this->metadataReader->read($path);
            $written = $this->reader->read($metadata->rawMetadata);
            if ($written->playCount !== $statistics->play_count) {
                throw new RuntimeException('The written play count could not be verified.');
            }

            clearstatcache(true, $path);
            $modifiedAt = filemtime($path);
            $fileSize = filesize($path);
            if ($modifiedAt === false || $fileSize === false) {
                throw new RuntimeException('The updated audio-file fingerprint could not be read.');
            }

            $mediaFile->update([
                'file_size' => $fileSize,
                'modified_at' => CarbonImmutable::createFromTimestampUTC($modifiedAt),
                'raw_metadata' => $metadata->rawMetadata,
            ]);
            $this->recordResult($statistics, 'synchronized');
        } catch (UnsupportedPlaybackStatisticsTagFormat $exception) {
            $this->recordResult($statistics, 'unsupported', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->recordResult($statistics, 'failed', $exception->getMessage());

            throw $exception;
        }
    }

    private function recordResult(TrackPlayStatistic $statistics, string $status, ?string $error = null): void
    {
        $sourceMetadata = $statistics->source_metadata ?? [];
        $sourceMetadata['file_tags_export'] = array_filter([
            'status' => $status,
            'attempted_at' => now()->toJSON(),
            'play_count' => $statistics->play_count,
            'first_played_at' => $statistics->first_played_at?->toJSON(),
            'last_played_at' => $statistics->last_played_at?->toJSON(),
            'error' => $error === null ? null : mb_substr($error, 0, 1000),
        ], static fn (mixed $value): bool => $value !== null);
        $statistics->source_metadata = $sourceMetadata;
        $statistics->save();
    }
}
