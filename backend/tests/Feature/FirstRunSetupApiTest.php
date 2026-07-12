<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\Library;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstRunSetupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_progress_is_persisted(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/settings/first-run')
            ->assertOk()
            ->assertJson([
                'completed' => false,
                'step' => 1,
                'hasLibraryRoots' => false,
            ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->patchJson('/api/settings/first-run', ['step' => 3])
            ->assertOk()
            ->assertJsonPath('step', 3);

        $this->assertSame(3, ApplicationSetting::current()->setup_step);
    }

    public function test_setup_cannot_be_completed_without_a_library_root(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->patchJson('/api/settings/first-run', ['completed' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Add at least one library root before completing setup.');
    }

    public function test_setup_can_be_completed_after_a_library_root_is_added(): void
    {
        Library::create(['name' => 'Test Library'])->roots()->create([
            'name' => 'Music',
            'path' => storage_path('music'),
            'path_hash' => hash('sha256', mb_strtolower(storage_path('music'))),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->patchJson('/api/settings/first-run', ['step' => 5, 'completed' => true])
            ->assertOk()
            ->assertJson([
                'completed' => true,
                'step' => 5,
                'hasLibraryRoots' => true,
            ]);
    }
}
