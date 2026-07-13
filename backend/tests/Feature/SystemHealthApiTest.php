<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Library;
use App\Models\ScanRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemHealthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'app/private',
            'app/artwork',
            'framework/cache',
            'framework/sessions',
            'logs',
        ] as $path) {
            File::ensureDirectoryExists(storage_path($path));
        }
    }

    public function test_it_returns_runtime_health_information(): void
    {
        config(['queue.default' => 'database']);
        $backupStatusPath = storage_path('app/system-health-test-backup.json');
        config(['music-library.system_health.backup_status_path' => $backupStatusPath]);
        Cache::forever(
            (string) config('music-library.system_health.scheduler_heartbeat_key'),
            now()->toJSON(),
        );

        $rootPath = storage_path('app/system-health-test-root');
        File::ensureDirectoryExists($rootPath);
        File::put($backupStatusPath, json_encode([
            'operation' => 'backup',
            'status' => 'completed',
            'mode' => 'Development',
            'completedAt' => now()->toJSON(),
            'bundleName' => 'music-library-development-test',
            'bytes' => 1234,
        ], JSON_THROW_ON_ERROR));

        $root = Library::create(['name' => 'Test Library'])->roots()->create([
            'name' => 'Test Root',
            'path' => $rootPath,
            'path_hash' => hash('sha256', mb_strtolower($rootPath)),
        ]);

        ScanRun::create([
            'library_root_id' => $root->id,
            'status' => ScanStatus::Failed,
            'trigger' => ScanTrigger::Manual,
            'summary' => ['error' => 'The scan stopped.'],
            'finished_at' => now(),
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => "RuntimeException: Example failure\nStack trace",
            'failed_at' => now(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/settings/system-health')
            ->assertOk()
            ->assertJsonPath('database.status', 'ok')
            ->assertJsonPath('queue.status', 'warning')
            ->assertJsonPath('queue.failed', 1)
            ->assertJsonPath('scheduler.status', 'ok')
            ->assertJsonPath('backup.available', true)
            ->assertJsonPath('backup.bundleName', 'music-library-development-test')
            ->assertJsonPath('libraryRoots.0.name', 'Test Root')
            ->assertJsonPath('libraryRoots.0.readable', true)
            ->assertJsonPath('scans.latestFailed.0.message', 'The scan stopped.');
    }

    public function test_it_reports_a_stale_scheduler_heartbeat(): void
    {
        config([
            'queue.default' => 'sync',
            'music-library.system_health.scheduler_stale_seconds' => 180,
        ]);
        Cache::forever(
            (string) config('music-library.system_health.scheduler_heartbeat_key'),
            now()->subMinutes(5)->toJSON(),
        );

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/settings/system-health')
            ->assertOk()
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('scheduler.status', 'warning')
            ->assertJsonPath('scheduler.observable', true);
    }
}
