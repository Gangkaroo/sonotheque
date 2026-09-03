<?php

namespace Tests\Unit;

use App\System\Backups\ApplicationStorageArchive;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ApplicationStorageArchiveTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = storage_path('framework/testing/storage-archive-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->basePath.'/source/catalog');
        File::ensureDirectoryExists($this->basePath.'/source/system-backups');
        File::put($this->basePath.'/source/catalog/data.json', 'current');
        File::put($this->basePath.'/source/system-backups/operation.json', 'preserved');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_restores_application_storage_without_overwriting_internal_backup_state(): void
    {
        $archive = app(ApplicationStorageArchive::class);
        $archivePath = $this->basePath.'/storage.tar';
        $archive->create($this->basePath.'/source', $archivePath);
        File::put($this->basePath.'/source/catalog/data.json', 'changed');

        $archive->restore($archivePath, $this->basePath.'/source');

        $this->assertSame('current', File::get($this->basePath.'/source/catalog/data.json'));
        $this->assertSame(
            'preserved',
            File::get($this->basePath.'/source/system-backups/operation.json'),
        );
    }
}
