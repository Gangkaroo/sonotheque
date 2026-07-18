<?php

namespace App\Music\Scanning;

interface AudioContentFingerprinter
{
    public const VERSION = 1;

    public function fingerprint(string $absolutePath): string;
}
