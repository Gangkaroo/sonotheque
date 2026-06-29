<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaybackStatisticsSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_is_disabled_by_default_and_can_be_enabled(): void
    {
        $this->getJson('/api/settings/playback-statistics')
            ->assertOk()
            ->assertJsonPath('importFromFileTags', false)
            ->assertJsonPath('exportToFileTags', false);

        $this->patchJson('/api/settings/playback-statistics', [
            'importFromFileTags' => true,
        ])->assertOk()
            ->assertJsonPath('importFromFileTags', true)
            ->assertJsonPath('exportToFileTags', false);

        $this->assertTrue(ApplicationSetting::current()->import_play_statistics_from_tags);
    }

    public function test_import_setting_requires_a_boolean(): void
    {
        $this->patchJson('/api/settings/playback-statistics', [
            'importFromFileTags' => 'yes',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('importFromFileTags');
    }
}
