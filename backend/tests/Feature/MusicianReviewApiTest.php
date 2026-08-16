<?php

namespace Tests\Feature;

use App\Enums\MusicianReviewDecision;
use App\Enums\OnlineContentStatus;
use App\Jobs\RefreshAlbumMusicianCredits;
use App\Models\Album;
use App\Models\AlbumMusicianEnrichment;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Track;
use App\Music\Enrichment\AlbumMusicianCreditManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MusicianReviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_catalog_is_root_scoped_and_persists_reopenable_decisions(): void
    {
        $firstRoot = $this->createRoot('First', 'D:/First');
        $secondRoot = $this->createRoot('Second', 'E:/Second');
        $ambiguous = $this->createAlbum($firstRoot, 'Ambiguous Album');
        $failed = $this->createAlbum($firstRoot, 'Failed Album');
        $otherRoot = $this->createAlbum($secondRoot, 'Other Root Album');
        $releaseId = (string) Str::uuid();
        $this->enrichment($ambiguous, OnlineContentStatus::Ambiguous, [[
            'id' => $releaseId,
            'title' => 'Ambiguous Album',
            'artistName' => 'Ambiguous Album Artist',
            'formats' => ['CD'],
        ]]);
        $this->enrichment($failed, OnlineContentStatus::Error);
        $this->enrichment($otherRoot, OnlineContentStatus::Ambiguous);

        $this->getJson("/api/musician-reviews?status=ambiguous&libraryRoot={$firstRoot->id}")
            ->assertOk()
            ->assertJsonPath('counts.ambiguous', 1)
            ->assertJsonPath('counts.failed', 1)
            ->assertJsonPath('counts.reviewed', 0)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.album.id', $ambiguous->id)
            ->assertJsonPath('items.0.album.libraryRoot.name', 'First')
            ->assertJsonPath('items.0.candidateReleases.0.id', $releaseId);

        $this->putJson(
            "/api/musician-reviews/{$ambiguous->id}/decision?libraryRoot={$firstRoot->id}",
            ['decision' => MusicianReviewDecision::NoSuitableMatch->value],
        )
            ->assertOk()
            ->assertJsonPath('decision', MusicianReviewDecision::NoSuitableMatch->value);

        $this->getJson("/api/musician-reviews?status=reviewed&libraryRoot={$firstRoot->id}")
            ->assertOk()
            ->assertJsonPath('counts.ambiguous', 0)
            ->assertJsonPath('counts.reviewed', 1)
            ->assertJsonPath('items.0.review.decision', MusicianReviewDecision::NoSuitableMatch->value);

        $this->deleteJson(
            "/api/musician-reviews/{$ambiguous->id}/decision?libraryRoot={$firstRoot->id}",
        )
            ->assertOk()
            ->assertJsonPath('reviewed', false);

        $this->assertDatabaseMissing('album_musician_reviews', [
            'album_id' => $ambiguous->id,
            'lookup_version' => AlbumMusicianCreditManager::LOOKUP_VERSION,
        ]);
    }

    public function test_review_actions_retry_failures_and_clear_decisions_when_a_release_is_selected(): void
    {
        Queue::fake();
        ApplicationSetting::current()->update(['online_information_enabled' => true]);
        $root = $this->createRoot('Music', 'D:/Music');
        $failed = $this->createAlbum($root, 'Failed Album');
        $ambiguous = $this->createAlbum($root, 'Ambiguous Album');
        $releaseId = (string) Str::uuid();
        $this->enrichment($failed, OnlineContentStatus::Error);
        $this->enrichment($ambiguous, OnlineContentStatus::Ambiguous, [[
            'id' => $releaseId,
            'title' => 'Ambiguous Album',
            'formats' => [],
        ]]);

        $this->putJson(
            "/api/musician-reviews/{$failed->id}/decision",
            ['decision' => MusicianReviewDecision::Dismissed->value],
        )->assertOk();
        $this->postJson("/api/musician-reviews/{$failed->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('status', OnlineContentStatus::Pending->value);
        $this->assertDatabaseMissing('album_musician_reviews', ['album_id' => $failed->id]);
        $this->assertDatabaseHas('album_musician_enrichments', [
            'album_id' => $failed->id,
            'status' => OnlineContentStatus::Pending->value,
        ]);

        $this->putJson(
            "/api/musician-reviews/{$ambiguous->id}/decision",
            ['decision' => MusicianReviewDecision::Dismissed->value],
        )->assertOk();
        $this->putJson(
            "/api/enrichment/albums/{$ambiguous->id}/musicians/release",
            ['releaseId' => $releaseId],
        )
            ->assertOk()
            ->assertJsonPath('status', OnlineContentStatus::Pending->value);
        $this->assertDatabaseMissing('album_musician_reviews', ['album_id' => $ambiguous->id]);
        $this->assertDatabaseHas('album_musician_enrichments', [
            'album_id' => $ambiguous->id,
            'selected_release_id' => $releaseId,
            'status' => OnlineContentStatus::Pending->value,
        ]);
        Queue::assertPushed(
            RefreshAlbumMusicianCredits::class,
            fn (RefreshAlbumMusicianCredits $job): bool => in_array(
                $job->albumId,
                [$failed->id, $ambiguous->id],
                true,
            ),
        );
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
        $file = MediaFile::create([
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
            'media_file_id' => $file->id,
            'title' => $title.' Track',
            'sort_title' => $title.' Track',
        ]);

        return $album;
    }

    /** @param list<array<string, mixed>> $candidates */
    private function enrichment(
        Album $album,
        OnlineContentStatus $status,
        array $candidates = [],
    ): void {
        AlbumMusicianEnrichment::create([
            'album_id' => $album->id,
            'provider' => 'musicbrainz',
            'lookup_version' => AlbumMusicianCreditManager::LOOKUP_VERSION,
            'status' => $status,
            'candidate_releases' => $candidates,
            'failure_count' => $status === OnlineContentStatus::Error ? 2 : 0,
            'last_error_code' => $status === OnlineContentStatus::Error ? 'timeout' : null,
            'fetched_at' => now(),
        ]);
    }
}
