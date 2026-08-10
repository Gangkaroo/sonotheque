<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\AudioAnalysisArtifact;
use App\Models\AudioAnalysisProfile;
use App\Models\AudioAnalysisRun;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistOrderSnapshot;
use App\Models\Track;
use App\Music\Intelligence\AudioVectorIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistSimilarityOrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_similarity_order_can_be_previewed_applied_and_restored(): void
    {
        [$run, $rootId, $album, $artist] = $this->createAnalysisContext();
        $opening = $this->createTrack($run, $rootId, $album, $artist, 'Opening', $this->embedding(1, 0), 0);
        $far = $this->createTrack($run, $rootId, $album, $artist, 'Far', $this->embedding(0, 1), 1);
        $near = $this->createTrack($run, $rootId, $album, $artist, 'Near', $this->embedding(0.9, 0.1), 2);
        $unanalyzed = $this->createUnanalyzedTrack($rootId, $album, $artist, 'Unanalyzed', 3);
        $playlist = Playlist::create(['name' => 'Similarity route']);
        foreach ([$opening, $far, $near, $unanalyzed] as $position => $track) {
            $playlist->items()->create(['track_id' => $track->id, 'position' => $position]);
        }
        $items = $playlist->items()->orderBy('position')->get();

        $this->getJson("/api/playlists/{$playlist->id}/similarity-order")
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('available', false)
            ->assertJsonPath('analyzedItemIds.0', $items[0]->id)
            ->assertJsonPath('unanalyzedItemIds.0', $items[3]->id);
        $this->postJson("/api/playlists/{$playlist->id}/similarity-order/preview", [
            'openingItemId' => $items[0]->id,
        ])->assertStatus(409);

        ApplicationSetting::current()->update(['audio_intelligence_enabled' => true]);
        $this->getJson("/api/playlists/{$playlist->id}/similarity-order")
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('available', true)
            ->assertJsonPath('canUndo', false);
        $preview = $this->postJson("/api/playlists/{$playlist->id}/similarity-order/preview", [
            'openingItemId' => $items[0]->id,
        ]);

        $preview
            ->assertOk()
            ->assertJsonPath('algorithm', 'greedy_2opt')
            ->assertJsonPath('summary.analyzedCount', 3)
            ->assertJsonPath('summary.unanalyzedCount', 1)
            ->assertJsonPath('items.0.itemId', $items[0]->id)
            ->assertJsonPath('items.1.itemId', $items[2]->id)
            ->assertJsonPath('items.2.itemId', $items[1]->id)
            ->assertJsonPath('items.3.itemId', $items[3]->id)
            ->assertJsonPath('items.3.analyzed', false)
            ->assertJsonPath('canUndo', false);
        $this->assertGreaterThan(0, $preview->json('summary.improvement'));

        $proposedIds = collect($preview->json('items'))->pluck('itemId')->all();
        $this->patchJson("/api/playlists/{$playlist->id}/similarity-order", [
            'items' => $proposedIds,
            'orderSignature' => $preview->json('orderSignature'),
        ])
            ->assertOk()
            ->assertJsonPath('itemIds', $proposedIds)
            ->assertJsonPath('canUndo', true);

        $this->assertSame($proposedIds, $playlist->items()->orderBy('position')->pluck('id')->all());
        $this->assertDatabaseCount('playlist_order_snapshots', 1);
        $this->assertNull(PlaylistOrderSnapshot::firstOrFail()->restored_at);

        $this->postJson("/api/playlists/{$playlist->id}/similarity-order/restore")
            ->assertOk()
            ->assertJsonPath('itemIds', $items->pluck('id')->all())
            ->assertJsonPath('canUndo', false);

        $this->assertSame(
            $items->pluck('id')->all(),
            $playlist->items()->orderBy('position')->pluck('id')->all(),
        );
        $this->assertNotNull(PlaylistOrderSnapshot::firstOrFail()->fresh()->restored_at);
    }

    public function test_preview_requires_an_analyzed_opening_track_and_apply_rejects_stale_order(): void
    {
        [$run, $rootId, $album, $artist] = $this->createAnalysisContext();
        $analyzed = $this->createTrack($run, $rootId, $album, $artist, 'Analyzed', $this->embedding(1, 0), 0);
        $second = $this->createTrack($run, $rootId, $album, $artist, 'Second', $this->embedding(0, 1), 1);
        $unanalyzed = $this->createUnanalyzedTrack($rootId, $album, $artist, 'Missing vector', 2);
        $playlist = Playlist::create(['name' => 'Validation']);
        foreach ([$analyzed, $second, $unanalyzed] as $position => $track) {
            $playlist->items()->create(['track_id' => $track->id, 'position' => $position]);
        }
        $items = $playlist->items()->orderBy('position')->get();
        ApplicationSetting::current()->update(['audio_intelligence_enabled' => true]);

        $this->postJson("/api/playlists/{$playlist->id}/similarity-order/preview", [
            'openingItemId' => $items[2]->id,
        ])->assertUnprocessable();

        $preview = $this->postJson("/api/playlists/{$playlist->id}/similarity-order/preview", [
            'openingItemId' => $items[0]->id,
        ])->assertOk();
        $playlist->items()->whereKey($items[1]->id)->update(['position' => 0]);
        $playlist->items()->whereKey($items[0]->id)->update(['position' => 1]);

        $this->patchJson("/api/playlists/{$playlist->id}/similarity-order", [
            'items' => collect($preview->json('items'))->pluck('itemId')->all(),
            'orderSignature' => $preview->json('orderSignature'),
        ])->assertStatus(409);
    }

    /** @return array{AudioAnalysisRun, int, Album, Artist} */
    private function createAnalysisContext(): array
    {
        $root = Library::create(['name' => 'Ordering'])->roots()->create([
            'name' => 'Root',
            'path' => 'G:/Ordering',
            'path_hash' => hash('sha256', 'g:/ordering'),
            'enabled' => true,
        ]);
        $artist = Artist::create(['name' => 'Route Artist', 'sort_name' => 'Route Artist']);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Route Album',
            'sort_title' => 'Route Album',
            'relative_path' => 'Route Artist/Route Album',
            'relative_path_hash' => hash('sha256', 'route artist/route album'),
        ]);
        $profile = AudioAnalysisProfile::create([
            'profile_key' => 'playlist-ordering',
            'protocol_version' => 1,
            'analyzer_name' => 'Test analyzer',
            'analyzer_version' => '1',
            'analyzer_license' => 'Test license',
            'model_name' => 'Test vectors',
            'model_version' => '1',
            'model_checksum' => str_repeat('a', 64),
            'model_license' => 'Test model license',
            'embedding_dimensions' => AudioVectorIndex::DIMENSIONS,
            'sample_rate' => 16000,
            'manifest' => [],
        ]);
        $run = AudioAnalysisRun::create([
            'audio_analysis_profile_id' => $profile->id,
            'status' => 'completed',
            'selection_seed' => fake()->uuid(),
            'requested_track_count' => 4,
            'selected_track_count' => 3,
            'summary' => [],
        ]);

        return [$run, $root->id, $album, $artist];
    }

    /** @param list<float> $embedding */
    private function createTrack(
        AudioAnalysisRun $run,
        int $rootId,
        Album $album,
        Artist $artist,
        string $title,
        array $embedding,
        int $position,
    ): Track {
        $track = $this->createUnanalyzedTrack($rootId, $album, $artist, $title, $position);
        $fingerprint = hash('sha256', $title);
        $artifact = AudioAnalysisArtifact::create([
            'audio_analysis_profile_id' => $run->audio_analysis_profile_id,
            'content_fingerprint' => $fingerprint,
            'content_fingerprint_version' => 1,
            'features' => [],
            'embedding' => $embedding,
        ]);
        app(AudioVectorIndex::class)->synchronize($artifact, $embedding);
        $run->items()->create([
            'track_id' => $track->id,
            'library_root_id' => $rootId,
            'audio_analysis_artifact_id' => $artifact->id,
            'content_fingerprint' => $fingerprint,
            'content_fingerprint_version' => 1,
            'position' => $position,
            'status' => 'completed',
        ]);

        return $track;
    }

    private function createUnanalyzedTrack(
        int $rootId,
        Album $album,
        Artist $artist,
        string $title,
        int $position,
    ): Track {
        $relativePath = "Route Artist/Route Album/{$position}.mp3";
        $mediaFile = MediaFile::create([
            'library_root_id' => $rootId,
            'album_id' => $album->id,
            'relative_path' => $relativePath,
            'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
            'file_size' => 100,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $title,
            'sort_title' => $title,
            'track_number' => $position + 1,
        ]);
        $track->artists()->attach($artist->id, ['role' => 'primary', 'position' => 0]);

        return $track;
    }

    /** @return list<float> */
    private function embedding(float $first, float $second): array
    {
        $embedding = array_fill(0, AudioVectorIndex::DIMENSIONS, 0.0);
        $embedding[0] = $first;
        $embedding[1] = $second;

        return $embedding;
    }
}
