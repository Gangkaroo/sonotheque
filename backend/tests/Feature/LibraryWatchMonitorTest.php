<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanLibraryRoot;
use App\Models\Library;
use App\Models\LibraryActivityLog;
use App\Models\LibraryRoot;
use App\Models\ScanRun;
use App\Music\Scanning\LibraryActivityLogger;
use App\Music\Scanning\LibraryScanner;
use App\Music\Scanning\LibraryWatchMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LibraryWatchMonitorTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->musicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'watch-root-'.Str::uuid();
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album', recursive: true);
        file_put_contents(
            $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'01.mp3',
            'first track',
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->musicPath);

        parent::tearDown();
    }

    public function test_first_check_queues_reconciliation_and_later_change_queues_subtree_scan(): void
    {
        $root = $this->createRoot();
        $monitor = $this->app->make(LibraryWatchMonitor::class);

        $monitor->run();

        $firstScan = $root->scanRuns()->firstOrFail();
        $this->assertSame(ScanTrigger::Watcher, $firstScan->trigger);
        $this->assertNull($firstScan->subtree_path);
        $this->assertSame('scanning', $root->fresh()->watch_status);
        $this->assertGreaterThan(0, $root->watchDirectories()->count());
        Queue::assertPushed(ScanLibraryRoot::class, 1);

        $firstScan->update([
            'status' => ScanStatus::Completed,
            'finished_at' => now(),
        ]);
        $root->update([
            'last_scanned_at' => now(),
            'watch_checked_at' => now()->subMinutes(10),
            'watch_status' => 'watching',
        ]);
        file_put_contents(
            $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'02.mp3',
            'second track',
        );

        $monitor->run();

        $secondScan = $root->scanRuns()->latest('id')->firstOrFail();
        $this->assertNotSame($firstScan->id, $secondScan->id);
        $this->assertSame('Artist/Album', $secondScan->subtree_path);
        $this->assertSame(['Artist/Album'], $secondScan->scan_paths);
        $this->assertSame([], $secondScan->missing_paths);
        $this->assertDatabaseHas(LibraryActivityLog::class, [
            'library_root_id' => $root->id,
            'scan_run_id' => $secondScan->id,
            'source' => 'watcher',
            'code' => 'watch_change_detected',
        ]);
        Queue::assertPushed(ScanLibraryRoot::class, 2);
    }

    public function test_active_scan_keeps_detected_changes_pending(): void
    {
        $root = $this->createRoot();
        $this->app->make(LibraryWatchMonitor::class)->run();
        $root->update(['watch_checked_at' => now()->subMinutes(10)]);

        $this->app->make(LibraryWatchMonitor::class)->run();

        $this->assertSame('pending', $root->fresh()->watch_status);
        $this->assertSame(1, $root->scanRuns()->count());
    }

    public function test_moving_an_album_with_artwork_queues_an_artist_subtree_scan(): void
    {
        file_put_contents(
            $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'cover.jpg',
            'album cover',
        );
        $root = $this->createRoot();
        $monitor = $this->app->make(LibraryWatchMonitor::class);

        $monitor->run();
        $firstScan = $root->scanRuns()->sole();
        $firstScan->update([
            'status' => ScanStatus::Completed,
            'finished_at' => now(),
        ]);
        $root->update([
            'last_scanned_at' => now(),
            'watch_checked_at' => now()->subMinutes(10),
            'watch_status' => 'watching',
        ]);

        rename(
            $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album',
            $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Moved Album',
        );

        $monitor->run();

        $secondScan = $root->scanRuns()->latest('id')->firstOrFail();
        $this->assertNotSame($firstScan->id, $secondScan->id);
        $this->assertSame('Artist', $secondScan->subtree_path);
        $this->assertSame(['Artist/Moved Album'], $secondScan->scan_paths);
        $this->assertSame(['Artist/Album'], $secondScan->missing_paths);
        $this->assertDatabaseHas(LibraryActivityLog::class, [
            'library_root_id' => $root->id,
            'scan_run_id' => $secondScan->id,
            'source' => 'watcher',
            'code' => 'watch_change_detected',
        ]);
    }

    public function test_successful_initial_reconciliation_transitions_to_watching(): void
    {
        $root = $this->createRoot();
        $monitor = $this->app->make(LibraryWatchMonitor::class);
        $monitor->run();
        $scan = $root->scanRuns()->sole();
        $scanner = \Mockery::mock(LibraryScanner::class);
        $scanner->shouldReceive('scan')
            ->once()
            ->andReturnUsing(function (ScanRun $scanRun) use ($root): void {
                $scanRun->update([
                    'status' => ScanStatus::Completed,
                    'finished_at' => now(),
                ]);
                $root->update(['last_scanned_at' => now()]);
            });

        (new ScanLibraryRoot($scan->id))->handle(
            $scanner,
            $this->app->make(LibraryActivityLogger::class),
        );

        $this->assertSame('watching', $root->fresh()->watch_status);
        $this->assertNotNull($root->fresh()->watch_last_scan_at);

        $root->update(['watch_checked_at' => now()->subMinutes(10)]);
        $monitor->run();

        $this->assertSame(1, $root->scanRuns()->count());
        $this->assertSame('watching', $root->fresh()->watch_status);
    }

    public function test_cancelled_watcher_scan_does_not_reenable_a_disabled_watcher(): void
    {
        $root = $this->createRoot();
        $this->app->make(LibraryWatchMonitor::class)->run();
        $scan = $root->scanRuns()->sole();
        $root->watchDirectories()->delete();
        $root->update([
            'watch_enabled' => false,
            'watch_status' => 'disabled',
            'watch_checked_at' => null,
        ]);
        $scanner = \Mockery::mock(LibraryScanner::class);
        $scanner->shouldReceive('scan')
            ->once()
            ->andReturnUsing(function (ScanRun $scanRun): void {
                $scanRun->update([
                    'status' => ScanStatus::Cancelled,
                    'cancel_requested_at' => now(),
                    'finished_at' => now(),
                ]);
            });

        (new ScanLibraryRoot($scan->id))->handle(
            $scanner,
            $this->app->make(LibraryActivityLogger::class),
        );

        $root->refresh();
        $this->assertFalse($root->watch_enabled);
        $this->assertSame('disabled', $root->watch_status);
        $this->assertNull($root->watch_checked_at);
        $this->assertSame(0, $root->watchDirectories()->count());
    }

    public function test_failed_watcher_scan_resets_its_snapshot_for_retry(): void
    {
        $root = $this->createRoot();
        $this->app->make(LibraryWatchMonitor::class)->run();
        $scan = $root->scanRuns()->sole();
        $scanner = \Mockery::mock(LibraryScanner::class);
        $scanner->shouldReceive('scan')
            ->once()
            ->andReturnUsing(function (ScanRun $scanRun): void {
                $scanRun->update([
                    'status' => ScanStatus::Failed,
                    'finished_at' => now(),
                    'summary' => [
                        'phase' => 'failed',
                        'error' => 'The watched directory could not be read.',
                    ],
                ]);
            });

        (new ScanLibraryRoot($scan->id))->handle(
            $scanner,
            $this->app->make(LibraryActivityLogger::class),
        );

        $root->refresh();
        $this->assertSame('pending', $root->watch_status);
        $this->assertNull($root->watch_checked_at);
        $this->assertSame('The watched directory could not be read.', $root->watch_error);
        $this->assertSame(0, $root->watchDirectories()->count());
    }

    private function createRoot(): LibraryRoot
    {
        return Library::query()->create(['name' => 'Watch Library'])->roots()->create([
            'name' => 'Watch Root',
            'path' => str_replace('\\', '/', $this->musicPath),
            'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $this->musicPath))),
            'cover_image_paths' => ['cover.jpg'],
            'enabled' => true,
            'watch_enabled' => true,
            'watch_poll_interval_minutes' => 5,
            'watch_reconcile_interval_minutes' => 1440,
            'watch_status' => 'pending',
        ]);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($path);
    }
}
