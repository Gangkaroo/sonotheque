<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryActivityLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_log_can_be_filtered_across_library_roots(): void
    {
        $library = Library::query()->create(['name' => 'Home']);
        $first = $library->roots()->create([
            'name' => 'Archive',
            'path' => 'G:/Archive',
            'path_hash' => hash('sha256', 'g:/archive'),
            'cover_image_paths' => ['cover.jpg'],
        ]);
        $second = $library->roots()->create([
            'name' => 'Recent',
            'path' => 'P:/Recent',
            'path_hash' => hash('sha256', 'p:/recent'),
            'cover_image_paths' => ['cover.jpg'],
        ]);
        LibraryActivityLog::query()->create([
            'library_root_id' => $first->id,
            'source' => 'watcher',
            'severity' => 'error',
            'code' => 'watch_root_unavailable',
            'message' => 'The root is unavailable.',
        ]);
        LibraryActivityLog::query()->create([
            'library_root_id' => $second->id,
            'source' => 'scan',
            'severity' => 'warning',
            'code' => 'file_warning',
            'message' => 'A tag could not be read.',
        ]);

        $this->getJson("/api/library-activity?libraryRoot={$first->id}&severity=error")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.libraryRootName', 'Archive')
            ->assertJsonPath('items.0.code', 'watch_root_unavailable');
    }
}
