<?php

namespace App\Music\Metadata;

use App\Music\Scanning\AudioMetadata;

interface TrackMetadataWriter
{
    public function supports(string $path): bool;

    /** @param array<string, mixed> $values */
    public function write(string $path, array $values): AudioMetadata;
}
