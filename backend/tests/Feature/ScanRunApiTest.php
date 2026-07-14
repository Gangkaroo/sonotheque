<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Jobs\ScanLibraryRoot;
use App\Models\Library;
use App\Models\ScanRun;
use App\Models\ScanRunIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScanRunApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scan_can_be_started_listed_and_cancelled(): void
    {
        Queue::fake();
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/Music',
            'path_hash' => hash('sha256', 'd:/music'),
        ]);

        $response = $this->postJson('/api/scan_runs', [
            'libraryRootId' => (string) $root->id,
        ], ['Accept' => 'application/ld+json'])->assertCreated();

        $scanId = $response->json('id');
        $response
            ->assertJsonPath('libraryRootId', $root->id)
            ->assertJsonPath('status', ScanStatus::Pending->value);
        Queue::assertPushed(ScanLibraryRoot::class);

        $this->get('/api/scan_runs?libraryRoot='.$root->id, ['Accept' => 'application/ld+json'])
            ->assertOk()
            ->assertJsonCount(1, 'member')
            ->assertJsonPath('member.0.id', $scanId);

        $this->call(
            'PATCH',
            '/api/scan_runs/'.$scanId.'/cancel',
            server: [
                'CONTENT_TYPE' => 'application/merge-patch+json',
                'HTTP_ACCEPT' => 'application/ld+json',
            ],
            content: '{}',
        )
            ->assertOk()
            ->assertJsonPath('status', ScanStatus::Cancelled->value);
    }

    public function test_a_second_active_scan_is_rejected(): void
    {
        Queue::fake();
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/Music',
            'path_hash' => hash('sha256', 'd:/music'),
        ]);
        ScanRun::create([
            'library_root_id' => $root->id,
            'status' => ScanStatus::Running,
            'trigger' => 'manual',
        ]);

        $this->postJson('/api/scan_runs', ['libraryRootId' => (string) $root->id], [
            'Accept' => 'application/ld+json',
        ])->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_all_persisted_scan_issues_can_be_loaded_on_demand(): void
    {
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/Music',
            'path_hash' => hash('sha256', 'd:/music'),
        ]);
        $scan = ScanRun::create([
            'library_root_id' => $root->id,
            'status' => ScanStatus::Completed,
            'trigger' => 'manual',
            'warning_count' => 3,
        ]);
        ScanRunIssue::create([
            'scan_run_id' => $scan->id,
            'code' => 'file_warning',
            'severity' => 'warning',
            'message' => 'Malformed metadata',
            'path' => 'Artist/Album/Track.mp3',
            'occurrence_count' => 1,
        ]);
        ScanRunIssue::create([
            'scan_run_id' => $scan->id,
            'code' => 'files_removed',
            'severity' => 'warning',
            'message' => 'Files removed',
            'occurrence_count' => 2,
        ]);

        $this->getJson('/api/scan_runs/'.$scan->id.'/issues')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.path', 'Artist/Album/Track.mp3')
            ->assertJsonPath('items.1.count', 2)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('totalOccurrences', 3);
    }

    public function test_a_stale_running_scan_is_failed_before_a_new_scan_is_started(): void
    {
        Queue::fake();
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => 'D:/Music',
            'path_hash' => hash('sha256', 'd:/music'),
        ]);
        $staleScan = ScanRun::create([
            'library_root_id' => $root->id,
            'status' => ScanStatus::Running,
            'trigger' => 'manual',
        ]);
        $staleScan->timestamps = false;
        $staleScan->forceFill(['updated_at' => now()->subMinutes(20)])->save();

        $this->postJson('/api/scan_runs', ['libraryRootId' => (string) $root->id], [
            'Accept' => 'application/ld+json',
        ])->assertCreated();

        $this->assertSame(ScanStatus::Failed, $staleScan->fresh()->status);
        $this->assertSame('worker_stopped', $staleScan->fresh()->summary['issues'][0]['code']);
        Queue::assertPushed(ScanLibraryRoot::class);
    }

    public function test_a_scan_can_be_scoped_to_a_valid_root_relative_subtree(): void
    {
        Queue::fake();
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'scan-subtree-'.Str::uuid();
        mkdir($path.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album', recursive: true);

        try {
            $root = Library::create(['name' => 'Test'])->roots()->create([
                'name' => 'Music',
                'path' => $path,
                'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $path))),
            ]);

            $this->postJson('/api/scan_runs', [
                'libraryRootId' => (string) $root->id,
                'subtreePath' => 'Artist\\Album/',
            ], ['Accept' => 'application/ld+json'])
                ->assertCreated()
                ->assertJsonPath('subtreePath', 'Artist/Album');

            $this->postJson('/api/scan_runs', [
                'libraryRootId' => (string) $root->id,
                'subtreePath' => '../outside',
            ], ['Accept' => 'application/ld+json'])->assertUnprocessable();
        } finally {
            rmdir($path.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album');
            rmdir($path.DIRECTORY_SEPARATOR.'Artist');
            rmdir($path);
        }
    }
}
