<?php

namespace App\Music\Scanning;

use App\Enums\MediaFileStatus;
use App\Music\PlaybackStatistics\ImportedPlayStatistics;

final class ScanMediaFileState
{
    public bool $moved = false;

    public ?int $trackId = null;

    public ?ImportedPlayStatistics $playStatistics = null;

    public function __construct(
        public readonly int $id,
        public readonly ?int $albumId,
        public readonly MediaFileStatus $status,
        public readonly int $fileSize,
        public readonly int $modifiedAt,
        public readonly int $metadataParserVersion,
        public ?string $contentFingerprint,
        public ?int $contentFingerprintVersion,
    ) {
    }
}
