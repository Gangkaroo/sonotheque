<?php

namespace App\Music\Scanning;

interface AudioMetadataProbe
{
    public function probe(string $absolutePath): ProbedAudioMetadata;
}
