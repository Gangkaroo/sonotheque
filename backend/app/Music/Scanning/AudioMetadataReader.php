<?php

namespace App\Music\Scanning;

interface AudioMetadataReader
{
    public function read(string $absolutePath): AudioMetadata;
}
