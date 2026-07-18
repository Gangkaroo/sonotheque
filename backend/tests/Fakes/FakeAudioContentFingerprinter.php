<?php

namespace Tests\Fakes;

use App\Music\Scanning\AudioContentFingerprinter;
use RuntimeException;

class FakeAudioContentFingerprinter implements AudioContentFingerprinter
{
    public int $calls = 0;

    public function fingerprint(string $absolutePath): string
    {
        $this->calls++;
        $contents = file_get_contents($absolutePath);

        if ($contents === false) {
            throw new RuntimeException('The test audio file could not be read.');
        }

        $audioMarker = '|AUDIO|';
        $audioOffset = strpos($contents, $audioMarker);
        $audioContents = $audioOffset === false
            ? $contents
            : substr($contents, $audioOffset + strlen($audioMarker));

        return hash('sha256', $audioContents);
    }
}
