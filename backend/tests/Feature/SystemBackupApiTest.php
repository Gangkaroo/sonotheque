<?php

namespace Tests\Feature;

use App\Jobs\CreateSystemBackup;
use App\Jobs\RestoreSystemBackup;
use App\System\Backups\SystemBackupManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class SystemBackupApiTest extends TestCase
{
    use RefreshDatabase;

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = storage_path('framework/testing/system-backup-api-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->basePath.'/destination');
        config([
            'sonotheque.system_backups.operation_path' => $this->basePath.'/operations',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_queues_a_complete_backup_and_exposes_its_status(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/settings/system-backups', [
            'destination' => $this->basePath.'/destination',
        ])->assertAccepted()
            ->assertJsonPath('type', 'backup')
            ->assertJsonPath('status', 'queued');

        $operationId = $response->json('id');
        $this->getJson("/api/settings/system-backups/operations/{$operationId}")
            ->assertOk()
            ->assertJsonPath('id', $operationId);
        Queue::assertPushed(
            CreateSystemBackup::class,
            fn (CreateSystemBackup $job): bool => $job->operationId === $operationId
                && $job->queue === 'scans',
        );
    }

    public function test_it_inspects_and_queues_a_confirmed_restore(): void
    {
        Queue::fake();
        $archivePath = $this->basePath.'/destination/backup.sonotheque-backup';
        File::put($archivePath, 'archive');
        $this->mock(SystemBackupManager::class, function (MockInterface $mock) use ($archivePath): void {
            $mock->shouldReceive('inspect')->twice()->with($archivePath)->andReturn([
                'path' => $archivePath,
                'name' => basename($archivePath),
                'createdAt' => now()->toJSON(),
                'mode' => 'Development',
                'database' => 'sonotheque',
                'bytes' => 7,
                'modeMatches' => true,
                'appKeyMatches' => true,
            ]);
        });

        $this->postJson('/api/settings/system-backups/inspect', ['path' => $archivePath])
            ->assertOk()
            ->assertJsonPath('appKeyMatches', true);
        $response = $this->postJson('/api/settings/system-backups/restore', [
            'path' => $archivePath,
            'confirmed' => true,
        ])->assertAccepted()
            ->assertJsonPath('type', 'restore');

        $operationId = $response->json('id');
        Queue::assertPushed(
            RestoreSystemBackup::class,
            fn (RestoreSystemBackup $job): bool => $job->operationId === $operationId
                && $job->archivePath === $archivePath
                && $job->queue === 'scans',
        );
    }

    public function test_it_refuses_a_restore_from_a_different_runtime_mode(): void
    {
        Queue::fake();
        $archivePath = $this->basePath.'/destination/backup.sonotheque-backup';
        File::put($archivePath, 'archive');
        $this->mock(SystemBackupManager::class, function (MockInterface $mock) use ($archivePath): void {
            $mock->shouldReceive('inspect')->once()->with($archivePath)->andReturn([
                'path' => $archivePath,
                'name' => basename($archivePath),
                'createdAt' => now()->toJSON(),
                'mode' => 'Packaged',
                'database' => 'sonotheque',
                'bytes' => 7,
                'modeMatches' => false,
                'appKeyMatches' => true,
            ]);
        });

        $this->postJson('/api/settings/system-backups/restore', [
            'path' => $archivePath,
            'confirmed' => true,
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'This backup was created in a different runtime mode. Use the documented command-line migration workflow instead.',
            );

        Queue::assertNotPushed(RestoreSystemBackup::class);
    }
}
