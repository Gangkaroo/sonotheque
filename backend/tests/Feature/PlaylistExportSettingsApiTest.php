<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\PlaylistExportLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistExportSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        usort($this->paths, fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($this->paths as $path) {
            @rmdir($path);
        }

        parent::tearDown();
    }

    public function test_it_manages_default_format_and_named_export_locations(): void
    {
        $firstPath = $this->temporaryDirectory('main');
        $secondPath = $this->temporaryDirectory('portable');

        $this->getJson('/api/settings/playlist-exports')
            ->assertOk()
            ->assertJsonPath('defaultFormat', 'm3u8')
            ->assertJsonPath('synchronizePlaylists', false)
            ->assertJsonPath('synchronization.pendingCount', 0)
            ->assertJsonPath('locations', []);

        $first = $this->postJson('/api/settings/playlist-exports/locations', [
            'name' => 'Main playlists',
            'path' => $firstPath,
        ])->assertCreated()
            ->assertJsonPath('locations.0.isDefault', true)
            ->json('locations.0');

        $second = $this->postJson('/api/settings/playlist-exports/locations', [
            'name' => 'Portable player',
            'path' => $secondPath,
            'makeDefault' => true,
        ])->assertCreated()
            ->assertJsonPath('locations.0.isDefault', true)
            ->json('locations.0');

        $this->assertFalse(PlaylistExportLocation::findOrFail($first['id'])->is_default);
        $this->assertTrue(PlaylistExportLocation::findOrFail($second['id'])->is_default);

        $this->patchJson('/api/settings/playlist-exports', [
            'defaultFormat' => 'm3u',
        ])->assertOk()
            ->assertJsonPath('defaultFormat', 'm3u');

        $this->assertSame('m3u', ApplicationSetting::current()->playlist_export_format);
    }

    public function test_synchronization_requires_a_default_export_location(): void
    {
        $this->patchJson('/api/settings/playlist-exports', [
            'defaultFormat' => 'm3u8',
            'synchronizePlaylists' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('synchronizePlaylists');

        $this->assertFalse(ApplicationSetting::current()->synchronize_playlists_to_files);
    }

    public function test_it_can_create_a_named_subfolder_inside_the_selected_location(): void
    {
        $parent = $this->temporaryDirectory('parent');
        $expectedPath = $parent.DIRECTORY_SEPARATOR.'My：Playlists';
        $this->paths[] = $expectedPath;

        $this->postJson('/api/settings/playlist-exports/locations', [
            'name' => 'My:Playlists',
            'path' => $parent,
            'createSubfolder' => true,
        ])->assertCreated()
            ->assertJsonPath(
                'locations.0.path',
                str_replace('\\', '/', realpath($expectedPath)),
            );

        $this->assertDirectoryExists($expectedPath);
    }

    public function test_it_updates_locations_rejects_duplicates_and_promotes_a_new_default(): void
    {
        $firstPath = $this->temporaryDirectory('first');
        $secondPath = $this->temporaryDirectory('second');
        $renamedPath = $this->temporaryDirectory('renamed');

        $first = PlaylistExportLocation::create([
            'name' => 'First',
            'path' => $firstPath,
            'path_hash' => $this->pathHash($firstPath),
            'is_default' => true,
        ]);
        $second = PlaylistExportLocation::create([
            'name' => 'Second',
            'path' => $secondPath,
            'path_hash' => $this->pathHash($secondPath),
            'is_default' => false,
        ]);

        $this->patchJson("/api/settings/playlist-exports/locations/{$second->id}", [
            'name' => 'Renamed',
            'path' => $renamedPath,
        ])->assertOk()
            ->assertJsonPath('locations.1.name', 'Renamed');

        $this->patchJson("/api/settings/playlist-exports/locations/{$second->id}", [
            'name' => 'Duplicate',
            'path' => $firstPath,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('path');

        $this->deleteJson("/api/settings/playlist-exports/locations/{$first->id}")
            ->assertOk()
            ->assertJsonPath('locations.0.id', $second->id)
            ->assertJsonPath('locations.0.isDefault', true);
    }

    private function temporaryDirectory(string $name): string
    {
        $path = storage_path(
            'framework/testing/playlist-export-'.$name.'-'.bin2hex(random_bytes(6)),
        );
        mkdir($path, 0777, true);
        $this->paths[] = $path;

        return $path;
    }

    private function pathHash(string $path): string
    {
        $normalized = str_replace('\\', '/', realpath($path) ?: $path);
        if (PHP_OS_FAMILY === 'Windows') {
            $normalized = mb_strtolower($normalized);
        }

        return hash('sha256', $normalized);
    }
}
