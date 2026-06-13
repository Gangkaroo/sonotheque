<?php

namespace App\Music\Scanning;

final readonly class DiscoveredAudioFile
{
    public function __construct(
        public string $absolutePath,
        public string $relativePath,
        public string $albumRelativePath,
        public string $artistFolder,
        public string $albumFolder,
        public int $fileSize,
        public int $modifiedAt,
    ) {}
}
