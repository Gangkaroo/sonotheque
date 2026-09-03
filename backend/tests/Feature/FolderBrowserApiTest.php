<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

class FolderBrowserApiTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'folder-browser-'.Str::uuid();
        mkdir($this->path.DIRECTORY_SEPARATOR.'Beta', recursive: true);
        mkdir($this->path.DIRECTORY_SEPARATOR.'alpha', recursive: true);
        file_put_contents($this->path.DIRECTORY_SEPARATOR.'track.mp3', 'not listed');
        file_put_contents($this->path.DIRECTORY_SEPARATOR.'backup.sonotheque-backup', 'backup');
    }

    protected function tearDown(): void
    {
        unlink($this->path.DIRECTORY_SEPARATOR.'track.mp3');
        unlink($this->path.DIRECTORY_SEPARATOR.'backup.sonotheque-backup');
        rmdir($this->path.DIRECTORY_SEPARATOR.'alpha');
        rmdir($this->path.DIRECTORY_SEPARATOR.'Beta');
        rmdir($this->path);

        parent::tearDown();
    }

    public function test_it_lists_readable_directories_without_files(): void
    {
        $response = $this->getJson('/api/folders?path='.urlencode($this->path))->assertOk();

        $response
            ->assertJsonPath('path', str_replace('\\', '/', realpath($this->path)))
            ->assertJsonCount(2, 'directories')
            ->assertJsonPath('directories.0.name', 'alpha')
            ->assertJsonPath('directories.1.name', 'Beta');
    }

    public function test_it_rejects_a_missing_directory(): void
    {
        $this->getJson('/api/folders?path='.urlencode($this->path.DIRECTORY_SEPARATOR.'missing'))
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'does not exist'));
    }

    public function test_it_can_list_only_sonotheque_backup_files_for_restore(): void
    {
        $this->getJson(
            '/api/folders?systemBackupFiles=1&path='.urlencode($this->path),
        )->assertOk()
            ->assertJsonCount(1, 'files')
            ->assertJsonPath('files.0.name', 'backup.sonotheque-backup');
    }
}
