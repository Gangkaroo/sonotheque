<?php

namespace Tests\Fakes;

use App\Music\Scanning\AudioMetadata;
use App\Music\Scanning\AudioMetadataReader;
use Closure;
use RuntimeException;

class FakeAudioMetadataReader implements AudioMetadataReader
{
    public int $calls = 0;

    public ?Closure $beforeRead = null;

    /** @var list<string> */
    private array $failPaths = [];

    public function __construct(private readonly AudioMetadata $metadata)
    {
    }

    public function read(string $absolutePath): AudioMetadata
    {
        $this->calls++;

        if ($this->beforeRead !== null) {
            ($this->beforeRead)();
        }

        foreach ($this->failPaths as $path) {
            if (str_contains($absolutePath, $path)) {
                throw new RuntimeException('The test audio file is malformed.');
            }
        }

        return $this->metadata;
    }

    public function failOn(string $path): void
    {
        $this->failPaths[] = $path;
    }
}
