<?php

namespace Tests\Unit;

use App\System\Backups\SystemBackupArchive;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class SystemBackupArchiveTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = storage_path('framework/testing/system-backup-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->basePath.'/staging');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_creates_extracts_and_verifies_a_single_backup_archive(): void
    {
        foreach ([
            'database.dump' => 'database',
            'storage.tar' => 'storage',
            'app-key.txt' => 'base64:key',
        ] as $name => $contents) {
            File::put($this->basePath.'/staging/'.$name, $contents);
        }
        $path = $this->basePath.'/backup.sonotheque-backup';
        $archive = app(SystemBackupArchive::class);

        $manifest = $archive->create($this->basePath.'/staging', $path, 'Development');
        $result = $archive->extract($path, $this->basePath.'/extracted');

        $this->assertFileExists($path);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('Development', $result['manifest']['mode']);
        $this->assertSame('database', File::get($this->basePath.'/extracted/database.dump'));
    }

    public function test_it_reports_a_malformed_manifest_as_an_invalid_archive(): void
    {
        $path = $this->basePath.'/invalid.sonotheque-backup';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE));
        foreach ([
            'manifest.json' => '{invalid',
            'database.dump' => 'database',
            'storage.tar' => 'storage',
            'app-key.txt' => 'base64:key',
        ] as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }
        $this->assertTrue($zip->close());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The backup manifest is not valid JSON.');

        app(SystemBackupArchive::class)->extract($path, $this->basePath.'/invalid-extracted');
    }
}
