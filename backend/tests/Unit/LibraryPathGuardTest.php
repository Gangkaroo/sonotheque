<?php

namespace Tests\Unit;

use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LibraryPathGuardTest extends TestCase
{
    public function test_it_normalizes_safe_relative_paths(): void
    {
        $guard = new LibraryPathGuard;

        $this->assertSame('artwork/front.jpg', $guard->normalizeRelativePath('artwork\\front.jpg'));
    }

    #[DataProvider('unsafePaths')]
    public function test_it_rejects_unsafe_relative_paths(string $path): void
    {
        $this->expectException(InvalidLibraryPath::class);

        (new LibraryPathGuard)->normalizeRelativePath($path);
    }

    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return [
            'empty' => [''],
            'absolute unix' => ['/cover.jpg'],
            'absolute windows' => ['C:\\cover.jpg'],
            'parent traversal' => ['../cover.jpg'],
            'nested traversal' => ['artwork/../cover.jpg'],
            'empty segment' => ['artwork//cover.jpg'],
        ];
    }
}
