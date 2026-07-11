<?php

namespace App\Music\Metadata;

use App\Models\MetadataEditItem;
use App\Models\MetadataEditJob;
use App\Models\Track;
use App\Music\Scanning\LibraryPathGuard;
use Carbon\CarbonImmutable;
use RuntimeException;

class TrackMetadataEditExecutor
{
    public function __construct(
        private readonly TrackMetadataEditing $editing,
        private readonly TrackMetadataWriter $writer,
        private readonly TrackMetadataCatalogUpdater $catalogUpdater,
        private readonly LibraryPathGuard $pathGuard,
        private readonly MetadataBackupManager $backups,
    ) {
    }

    public function applyTrackEdit(MetadataEditJob $edit): void
    {
        $this->apply(
            $edit,
            $edit->track,
            $edit->fingerprint,
            $edit->requested_changes,
            null,
            'The track changed after the edit was queued.',
        );
    }

    public function applyBatchItem(MetadataEditJob $edit, MetadataEditItem $item): void
    {
        $this->apply(
            $edit,
            $item->track,
            $item->fingerprint,
            $item->requested_changes,
            $item,
            'The track changed after the batch edit was queued.',
        );
    }

    /** @param  array<string, mixed>  $changes */
    private function apply(
        MetadataEditJob $edit,
        Track $track,
        string $fingerprint,
        array $changes,
        ?MetadataEditItem $item,
        string $changedMessage,
    ): void {
        if (! hash_equals($fingerprint, $this->editing->fingerprint($track))) {
            throw new RuntimeException($changedMessage);
        }

        $mediaFile = $track->mediaFile;
        $path = $this->pathGuard->resolveExistingFileWithin(
            $mediaFile->libraryRoot->path,
            $mediaFile->relative_path,
        );
        if ($path === null) {
            throw new RuntimeException('The audio file no longer exists.');
        }

        $this->backups->create($edit, $mediaFile, $path, $item);
        $metadata = $this->writer->write($path, $changes);
        clearstatcache(true, $path);
        $modifiedAt = filemtime($path);
        $fileSize = filesize($path);
        if ($modifiedAt === false || $fileSize === false) {
            throw new RuntimeException('The updated audio-file fingerprint could not be read.');
        }

        $this->catalogUpdater->apply(
            $track,
            $metadata,
            $fileSize,
            CarbonImmutable::createFromTimestampUTC($modifiedAt),
        );
    }
}
