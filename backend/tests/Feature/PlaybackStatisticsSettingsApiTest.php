<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaybackStatisticsSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_synchronization_is_disabled_by_default_and_can_be_enabled(): void
    {
        $this->getJson('/api/settings/playback-statistics')
            ->assertOk()
            ->assertJsonPath('synchronizeWithFileTags', false)
            ->assertJsonPath('synchronizeRatingsWithFileTags', false)
            ->assertJsonPath('supportedExportFormats.0', 'mp3');

        $this->patchJson('/api/settings/playback-statistics', [
            'synchronizeWithFileTags' => true,
        ])->assertOk()
            ->assertJsonPath('synchronizeWithFileTags', true);

        $this->assertTrue(ApplicationSetting::current()->import_play_statistics_from_tags);
        $this->assertTrue(ApplicationSetting::current()->export_play_statistics_to_tags);

        $this->patchJson('/api/settings/playback-statistics', [
            'synchronizeRatingsWithFileTags' => true,
        ])->assertOk()
            ->assertJsonPath('synchronizeRatingsWithFileTags', true);
        $this->assertTrue(ApplicationSetting::current()->synchronize_ratings_with_tags);
    }

    public function test_synchronization_setting_requires_a_boolean(): void
    {
        $this->patchJson('/api/settings/playback-statistics', [
            'synchronizeWithFileTags' => 'yes',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('synchronizeWithFileTags');

        $this->patchJson('/api/settings/playback-statistics', [
            'synchronizeRatingsWithFileTags' => 'yes',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('synchronizeRatingsWithFileTags');
    }
}
