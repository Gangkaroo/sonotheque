<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Library;
use App\Models\ScanRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemHealthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_runtime_health_information(): void
    {
        config(['queue.default' => 'database']);

        $rootPath = storage_path('app/system-health-test-root');
        File::ensureDirectoryExists($rootPath);

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
            ->assertJsonPath('libraryRoots.0.name', 'Test Root')
            ->assertJsonPath('libraryRoots.0.readable', true)
            ->assertJsonPath('scans.latestFailed.0.message', 'The scan stopped.');
    }
}
