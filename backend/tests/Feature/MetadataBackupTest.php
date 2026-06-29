<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\MetadataBackup;
use App\Models\MetadataEditJob;
use App\Models\Track;
use App\Music\Metadata\MetadataBackupManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MetadataBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    private string $backupPath;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(6));
        $this->musicPath = storage_path("framework/testing/metadata-backup-music-{$suffix}");
        $this->backupPath = storage_path("framework/testing/metadata-backup-files-{$suffix}");
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album', 0777, true);
        mkdir($this->backupPath, 0777, true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->deleteDirectory($this->musicPath);
        $this->deleteDirectory($this->backupPath);
        parent::tearDown();
    }

    public function test_settings_are_disabled_by_default_and_reject_a_path_inside_a_library(): void
    {
        $track = $this->createTrack();
        $insideLibrary = $this->musicPath.DIRECTORY_SEPARATOR.'Backups';
        mkdir($insideLibrary);

        $this->getJson('/api/settings/metadata-backups')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('retentionDays', 30);

        $this->patchJson('/api/settings/metadata-backups', [
            'enabled' => true,
            'path' => $insideLibrary,
            'retentionDays' => 14,
        ])->assertUnprocessable()->assertJsonValidationErrors('path');

        $this->patchJson('/api/settings/metadata-backups', [
            'enabled' => true,
            'path' => $this->backupPath,
            'retentionDays' => 14,
        ])->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('retentionDays', 14);

        $this->assertTrue(ApplicationSetting::current()->metadata_backups_enabled);
        $this->assertSame($track->mediaFile->libraryRoot->id, $track->mediaFile->library_root_id);
    }

    public function test_it_creates_one_verified_backup_per_edit_and_cleans_up_expired_files(): void
    {
        CarbonImmutable::setTestNow('2026-06-29 12:00:00 UTC');
        [$track, $job, $source] = $this->editContext();
        ApplicationSetting::current()->update([
            'metadata_backups_enabled' => true,
            'metadata_backup_path' => $this->backupPath,
            'metadata_backup_retention_days' => 7,
        ]);
        $manager = $this->app->make(MetadataBackupManager::class);

        $backup = $manager->create($job, $track->mediaFile, $source);
        $sameBackup = $manager->create($job, $track->mediaFile, $source);

        $this->assertNotNull($backup);
        $this->assertTrue($backup->is($sameBackup));
        $this->assertSame('original audio', file_get_contents($manager->absolutePath($backup)));
        $this->assertSame(hash('sha256', 'original audio'), $backup->checksum);
        $this->assertStringContainsString("library-{$track->mediaFile->library_root_id}/", $backup->backup_relative_path);

        $backup->update(['expires_at' => now()->subSecond()]);
        $this->assertSame(0, Artisan::call('music:metadata-backups:cleanup'));
        $this->assertStringContainsString('Removed 1 expired', Artisan::output());
        $this->assertFalse(is_file($manager->absolutePath($backup)));
        $this->assertNotNull($backup->fresh()->deleted_at);
    }

    public function test_restore_command_replaces_the_current_file_from_a_verified_backup(): void
    {
        [$track, $job, $source] = $this->editContext();
        ApplicationSetting::current()->update([
            'metadata_backups_enabled' => true,
            'metadata_backup_path' => $this->backupPath,
            'metadata_backup_retention_days' => 30,
        ]);
        $backup = $this->app->make(MetadataBackupManager::class)
            ->create($job, $track->mediaFile, $source);
        file_put_contents($source, 'changed audio');

        $exitCode = Artisan::call('music:metadata-backups:restore', [
            'backup' => $backup->id,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('original audio', file_get_contents($source));
        $this->assertNotNull(MetadataBackup::findOrFail($backup->id)->restored_at);
        $this->assertStringContainsString('Run a library rescan', Artisan::output());
    }

    /** @return array{Track, MetadataEditJob, string} */
    private function editContext(): array
    {
        $track = $this->createTrack();
        $source = $this->musicPath.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album'.DIRECTORY_SEPARATOR.'track.mp3';
        file_put_contents($source, 'original audio');
        $track->mediaFile->update(['file_size' => filesize($source)]);
        $job = MetadataEditJob::create([
            'track_id' => $track->id,
            'media_file_id' => $track->media_file_id,
            'status' => 'pending',
            'fingerprint' => hash('sha256', 'test'),
            'requested_changes' => ['title' => 'Changed'],
            'preview' => ['file' => $track->mediaFile->relative_path],
        ]);

        return [$track, $job, $source];
    }

    private function createTrack(): Track
    {
        $artist = Artist::create(['name' => 'Artist', 'sort_name' => 'Artist', 'browse_initial' => 'A']);
        $root = Library::create(['name' => 'Test'])->roots()->create([
            'name' => 'Music',
            'path' => $this->musicPath,
            'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $this->musicPath))),
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Album',
            'sort_title' => 'Album',
            'relative_path' => 'Artist/Album',
            'relative_path_hash' => hash('sha256', 'artist/album'),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => 'Artist/Album/track.mp3',
            'relative_path_hash' => hash('sha256', 'artist/album/track.mp3'),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Track',
            'sort_title' => 'Track',
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        return $track;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($child) ? $this->deleteDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
