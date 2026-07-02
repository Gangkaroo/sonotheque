<?php

namespace Tests\Fakes;

use App\Music\Scanning\AudioMetadata;
use App\Music\Scanning\AudioMetadataReader;
use RuntimeException;

class ExtensionSensitiveId3MetadataReader implements AudioMetadataReader
{
    public function read(string $absolutePath): AudioMetadata
    {
        if (mb_strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) !== 'mp3') {
            throw new RuntimeException('unable to determine file format');
        }

        return (new TestId3MetadataReader())->read($absolutePath);
    }
}
