<?php

namespace Tests\Feature;

use App\Enums\OnlineContentStatus;
use App\Jobs\RunMusicianCreditBackfill;
use App\Models\Album;
use App\Models\AlbumMusicianEnrichment;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\MusicianCreditBackfillRun;
use App\Models\Track;
use App\Music\Enrichment\AlbumMusicianCreditManager;
use App\Music\Enrichment\MusicianCreditBackfillManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class MusicianCreditBackfillApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_root_scoped_and_skips_current_completed_albums(): void
    {
        Queue::fake();
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        $firstRoot = $this->createRoot('First', 'D:/First');
        $secondRoot = $this->createRoot('Second', 'E:/Second');
        $completed = $this->createAlbum($firstRoot, 'Completed');
        $pending = $this->createAlbum($firstRoot, 'Pending');
        $this->createAlbum($secondRoot, 'Other root');
        AlbumMusicianEnrichment::create([
            'album_id' => $completed->id,
            'provider' => 'musicbrainz',
            'lookup_version' => AlbumMusicianCreditManager::LOOKUP_VERSION,
            'status' => OnlineContentStatus::Ready,
        ]);

        $response = $this->postJson(
            "/api/settings/online-enrichment/musician-backfill?libraryRoot={$firstRoot->id}",
        );

        $response->assertAccepted()
            ->assertJsonPath('coverage.checkedAlbums', 1)
            ->assertJsonPath('coverage.totalAlbums', 2)
            ->assertJsonPath('run.libraryRoot.id', $firstRoot->id)
            ->assertJsonPath('run.totalAlbumCount', 1)
            ->assertJsonPath('run.processedAlbumCount', 0);
        $runId = (int) $response->json('run.id');
        $this->assertDatabaseHas('musician_credit_backfill_runs', [
            'id' => $runId,
            'library_root_id' => $firstRoot->id,
            'max_album_id' => $pending->id,
            'total_album_count' => 1,
        ]);
        Queue::assertPushed(
            RunMusicianCreditBackfill::class,
            fn (RunMusicianCreditBackfill $job): bool => $job->runId === $runId
                && $job->queue === 'default',
        );
    }

    public function test_backfill_records_outcomes_and_completes_from_its_checkpoint(): void
    {
        Queue::fake();
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        $root = $this->createRoot('Music', 'D:/Music');
        $album = $this->createAlbum($root, 'Album');
        $run = app(MusicianCreditBackfillManager::class)->start($root);
        $credits = Mockery::mock(AlbumMusicianCreditManager::class);
        $credits->shouldReceive('refresh')
            ->once()
            ->with($album->id)
            ->andReturn(OnlineContentStatus::NotFound);
        $backfills = app(MusicianCreditBackfillManager::class);

        (new RunMusicianCreditBackfill($run->id))->handle($credits, $backfills);

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertSame(1, $run->processed_album_count);
        $this->assertSame(1, $run->not_found_album_count);
        $this->assertSame($album->id, $run->last_album_id);

        (new RunMusicianCreditBackfill($run->id))->handle($credits, $backfills);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->finished_at);
    }

    public function test_backfill_waits_and_retries_the_same_album_after_rate_limiting(): void
    {
        Queue::fake();
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        $root = $this->createRoot('Music', 'D:/Music');
        $album = $this->createAlbum($root, 'Album');
        $run = MusicianCreditBackfillRun::create([
            'library_root_id' => $root->id,
            'lookup_version' => AlbumMusicianCreditManager::LOOKUP_VERSION,
            'status' => 'queued',
            'max_album_id' => $album->id,
            'total_album_count' => 1,
        ]);
        AlbumMusicianEnrichment::create([
            'album_id' => $album->id,
            'provider' => 'musicbrainz',
            'lookup_version' => AlbumMusicianCreditManager::LOOKUP_VERSION,
            'status' => OnlineContentStatus::Error,
            'last_error_code' => 'rate_limited',
            'retry_after' => now()->addMinutes(15),
        ]);
        $credits = Mockery::mock(AlbumMusicianCreditManager::class);
        $credits->shouldReceive('refresh')
            ->once()
            ->with($album->id)
            ->andReturn(OnlineContentStatus::Error);

        (new RunMusicianCreditBackfill($run->id))->handle(
            $credits,
            app(MusicianCreditBackfillManager::class),
        );

        $run->refresh();
        $this->assertSame('queued', $run->status);
        $this->assertSame(0, $run->processed_album_count);
        $this->assertNull($run->last_album_id);
        $this->assertTrue($run->retry_after?->isFuture() ?? false);
        Queue::assertPushed(
            RunMusicianCreditBackfill::class,
            fn (RunMusicianCreditBackfill $job): bool => $job->runId === $run->id
                && $job->delay !== null,
        );
    }

    public function test_backfill_can_be_paused_resumed_and_cancelled(): void
    {
        Queue::fake();
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        $root = $this->createRoot('Music', 'D:/Music');
        $this->createAlbum($root, 'Album');
        $run = app(MusicianCreditBackfillManager::class)->start($root);

        $this->postJson("/api/settings/online-enrichment/musician-backfill/{$run->id}/pause")
            ->assertOk()
            ->assertJsonPath('run.pauseRequested', true);
        app(MusicianCreditBackfillManager::class)->begin($run->fresh());
        $this->assertDatabaseHas('musician_credit_backfill_runs', [
            'id' => $run->id,
            'status' => 'paused',
        ]);

        $this->postJson("/api/settings/online-enrichment/musician-backfill/{$run->id}/resume")
            ->assertAccepted()
            ->assertJsonPath('run.status', 'queued');
        $this->deleteJson("/api/settings/online-enrichment/musician-backfill/{$run->id}")
            ->assertOk()
            ->assertJsonPath('run.status', 'cancelled');
    }

    private function createRoot(string $name, string $path): LibraryRoot
    {
        $library = Library::query()->firstOrCreate(['name' => 'Test']);

        return $library->roots()->create([
            'name' => $name,
            'path' => $path,
            'path_hash' => hash('sha256', mb_strtolower($path)),
        ]);
    }

    private function createAlbum(LibraryRoot $root, string $title): Album
    {
        $artist = Artist::create([
            'name' => $title.' Artist',
            'sort_name' => $title.' Artist',
            'browse_initial' => strtoupper($title[0]),
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => $title,
            'sort_title' => $title,
            'relative_path' => $title,
            'relative_path_hash' => hash('sha256', mb_strtolower($title)),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => "{$title}/track.mp3",
            'relative_path_hash' => hash('sha256', mb_strtolower("{$title}/track.mp3")),
            'file_size' => 100,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $title.' Track',
            'sort_title' => $title.' Track',
        ]);

        return $album;
    }
}
