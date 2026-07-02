<?php

namespace Tests\Fakes;

use App\Music\Scanning\AudioMetadata;
use RuntimeException;

class FailingAlbumTrackMetadataWriter extends FakeAlbumTrackMetadataWriter
{
    private int $writes = 0;

    public function write(string $path, array $values): AudioMetadata
    {
        $this->writes++;
        if ($this->writes === 2) {
            throw new RuntimeException('Simulated write failure.');
        }

        return parent::write($path, $values);
    }
}
