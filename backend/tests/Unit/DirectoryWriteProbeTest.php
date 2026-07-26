<?php

namespace Tests\Unit;

use App\Support\DirectoryWriteProbe;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class DirectoryWriteProbeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'directory-write-probe-'
            .Str::uuid();
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_checks_actual_file_creation_and_removes_the_probe(): void
    {
        $this->assertTrue((new DirectoryWriteProbe())->canWrite($this->directory));
        $this->assertSame([], File::files($this->directory));
    }

    public function test_it_rejects_a_missing_directory(): void
    {
        File::deleteDirectory($this->directory);

        $this->assertFalse((new DirectoryWriteProbe())->canWrite($this->directory));
    }
}
