<?php

namespace App\Music\Ratings;

use App\Models\Track;
use App\Music\Scanning\AudioMetadataReader;
use App\Music\Scanning\LibraryPathGuard;
use Carbon\CarbonImmutable;
use RuntimeException;

final class RatingFileSynchronizer
{
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly Mp3RatingTagWriter $writer,
        private readonly RatingTagReader $reader,
        private readonly AudioMetadataReader $metadataReader,
    ) {
    }

    public function synchronize(Track $track): void
    {
        $track->loadMissing(['mediaFile.libraryRoot', 'album']);
        $mediaFile = $track->mediaFile;
        if ($mediaFile === null) {
            return;
        }

        $path = $this->pathGuard->resolveExistingFileWithin(
            $mediaFile->libraryRoot->path,
            $mediaFile->relative_path,
        );
        if ($path === null) {
            throw new RuntimeException('The audio file no longer exists.');
        }
        if (! $this->writer->supports($path)) {
            return;
        }

        $this->writer->write(
            $path,
            $track->rating_half_steps,
            $track->album?->rating_half_steps,
        );
        $metadata = $this->metadataReader->read($path);
        $written = $this->reader->read($metadata->rawMetadata);
        if ($written->trackHalfSteps !== $track->rating_half_steps
            || $written->albumHalfSteps !== $track->album?->rating_half_steps) {
            throw new RuntimeException('The written ratings could not be verified.');
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
            'rating_tags_import_version' => RatingTagReader::IMPORT_VERSION,
        ]);
    }
}
