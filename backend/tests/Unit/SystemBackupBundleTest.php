<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Sonotheque\Packaging\SystemBackupBundle;
use Tests\TestCase;

require_once __DIR__.'/../../../scripts/lib/SystemBackupBundle.php';

final class SystemBackupBundleTest extends TestCase
{
    private string $bundlePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bundlePath = sys_get_temp_dir().'/sonotheque-backup-'.bin2hex(random_bytes(6));
        mkdir($this->bundlePath, 0777, true);
        file_put_contents($this->bundlePath.'/database.dump', 'database');
        file_put_contents($this->bundlePath.'/storage.tar', 'storage');
        file_put_contents($this->bundlePath.'/app-key.txt', 'base64:key');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->bundlePath.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->bundlePath);

        parent::tearDown();
    }

    public function test_it_creates_and_validates_a_cross_platform_manifest(): void
    {
        $bundle = new SystemBackupBundle();
        $bundle->create($this->bundlePath, 'Packaged', 'sonotheque');

        $manifest = $bundle->validate($this->bundlePath, 'Packaged');

        $this->assertSame(SystemBackupBundle::VERSION, $manifest['version']);
        $this->assertSame('sonotheque', $manifest['database']);
        $this->assertCount(3, $manifest['files']);
    }

    public function test_it_rejects_a_modified_backup_file(): void
    {
        $bundle = new SystemBackupBundle();
        $bundle->create($this->bundlePath, 'Packaged', 'sonotheque');
        file_put_contents($this->bundlePath.'/database.dump', 'changed');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backup checksum is invalid');

        $bundle->validate($this->bundlePath, 'Packaged');
    }
}
