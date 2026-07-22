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
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AudioSimilarityEvaluationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluation_lists_analyzed_tracks_and_orders_cosine_matches(): void
    {
        ApplicationSetting::current()->update(['audio_intelligence_enabled' => true]);
        $library = Library::create(['name' => 'Evaluation']);
        $root = $library->roots()->create([
            'name' => 'Root',
            'path' => 'G:/Evaluation',
            'path_hash' => hash('sha256', 'g:/evaluation'),
            'enabled' => true,
        ]);
        $artist = Artist::create(['name' => 'Evaluator', 'sort_name' => 'Evaluator']);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Vectors',
            'sort_title' => 'Vectors',
            'relative_path' => 'Evaluator/Vectors',
            'relative_path_hash' => hash('sha256', 'evaluator/vectors'),
            'original_release_year' => 2026,
        ]);
        $profile = AudioAnalysisProfile::create([
            'profile_key' => 'evaluation',
            'protocol_version' => 1,
            'analyzer_name' => 'Test analyzer',
            'analyzer_version' => '1',
            'analyzer_license' => 'Test license',
            'model_name' => 'Test vectors',
            'model_version' => '1',
            'model_checksum' => str_repeat('a', 64),
            'model_license' => 'Test model license',
            'embedding_dimensions' => 3,
            'sample_rate' => 16000,
            'manifest' => [],
        ]);
        $run = AudioAnalysisRun::create([
            'audio_analysis_profile_id' => $profile->id,
            'status' => 'completed',
            'selection_seed' => fake()->uuid(),
            'requested_track_count' => 3,
            'selected_track_count' => 3,
            'summary' => [],
        ]);

        $source = $this->createAnalyzedTrack(
            $run,
            $root->id,
            $album,
            $artist,
            'Source',
            [1.0, 0.0, 0.0],
            ['bpm' => 120.0, 'key' => 'C'],
            0,
        );
        $near = $this->createAnalyzedTrack(
            $run,
            $root->id,
            $album,
            $artist,
            'Near',
            [0.9, 0.1, 0.0],
            ['bpm' => 121.0, 'key' => 'C'],
            1,
        );
        $far = $this->createAnalyzedTrack(
            $run,
            $root->id,
            $album,
            $artist,
            'Far',
            [0.0, 1.0, 0.0],
            ['bpm' => 80.0, 'key' => 'G'],
            2,
        );

        $this->getJson('/api/settings/audio-intelligence/evaluation')
            ->assertOk()
            ->assertJsonPath('analyzedTrackCount', 3)
            ->assertJsonPath('profile.embeddingDimensions', 3)
            ->assertJsonPath('coverage.rootCount', 1)
            ->assertJsonPath('coverage.artistCount', 1)
            ->assertJsonPath('coverage.albumCount', 1)
            ->assertJsonPath('distributions.bpm.count', 3)
            ->assertJsonPath('distributions.bpm.median', 120)
            ->assertJsonCount(8, 'distributions.bpm.bins')
            ->assertJsonPath('feedbackSummary.relevant', 0)
            ->assertJsonPath('review.targetSourceCount', 3)
            ->assertJsonPath('review.matchCount', 10)
            ->assertJsonPath('review.quality.all.completedSourceCount', 0)
            ->assertJsonCount(3, 'review.sources')
            ->assertJsonCount(3, 'tracks');

        $response = $this->getJson(
            "/api/settings/audio-intelligence/evaluation/{$source->id}?limit=2",
        );

        $response
            ->assertOk()
            ->assertJsonPath('source.id', $source->id)
            ->assertJsonPath('source.streamUrl', "/api/tracks/{$source->id}/stream")
            ->assertJsonPath('source.durationMs', 123000)
            ->assertJsonPath('source.albumOriginalReleaseYear', 2026)
            ->assertJsonPath('candidateCount', 2)
            ->assertJsonPath('matches.0.id', $near->id)
            ->assertJsonPath('matches.1.id', $far->id)
            ->assertJsonPath('matches.0.features.bpm', 121)
            ->assertJsonCount(2, 'matches');
        $this->assertGreaterThan(
            $response->json('matches.1.similarity'),
            $response->json('matches.0.similarity'),
        );

        $this->getJson(
            "/api/settings/audio-intelligence/evaluation/{$source->id}"
                .'?excludeSameAlbum=1&excludeSameArtist=1',
        )
            ->assertOk()
            ->assertJsonPath('candidateCount', 0)
            ->assertJsonPath('filters.excludeSameAlbum', true)
            ->assertJsonPath('filters.excludeSameArtist', true)
            ->assertJsonCount(0, 'matches');

        $this->getJson("/api/audio-intelligence/tracks/{$source->id}/similar?limit=2")
            ->assertOk()
            ->assertJsonPath('source.id', $source->id)
            ->assertJsonPath('filters.excludeSameAlbum', true)
            ->assertJsonPath('filters.excludeSameArtist', true)
            ->assertJsonPath('candidateCount', 0)
            ->assertJsonCount(0, 'matches');

        $this->getJson(
            "/api/audio-intelligence/tracks/{$source->id}/similar"
                .'?limit=2&excludeSameAlbum=0&excludeSameArtist=0',
        )
            ->assertOk()
            ->assertJsonPath('matches.0.id', $near->id)
            ->assertJsonPath('matches.1.id', $far->id)
            ->assertJsonCount(2, 'matches');

        $this->putJson(
            "/api/settings/audio-intelligence/evaluation/{$source->id}"
                ."/matches/{$near->id}/feedback",
            ['verdict' => 'relevant'],
        )
            ->assertOk()
            ->assertJsonPath('feedback', 'relevant')
            ->assertJsonPath('feedbackSummary.relevant', 1)
            ->assertJsonPath('review.quality.all.ratedMatchCount', 1)
            ->assertJsonPath('review.quality.all.relevanceRate', 1);

        $this->getJson("/api/settings/audio-intelligence/evaluation/{$source->id}")
            ->assertOk()
            ->assertJsonPath('matches.0.feedback', 'relevant');

        $this->deleteJson(
            "/api/settings/audio-intelligence/evaluation/{$source->id}"
                ."/matches/{$near->id}/feedback",
        )
            ->assertOk()
            ->assertJsonPath('feedback', null)
            ->assertJsonPath('feedbackSummary.relevant', 0)
            ->assertJsonPath('review.quality.all.ratedMatchCount', 0);
    }

    public function test_evaluation_requires_opt_in_and_analyzed_source_track(): void
    {
        $library = Library::create(['name' => 'Unanalyzed']);
        $root = $library->roots()->create([
            'name' => 'Root',
            'path' => 'G:/Unanalyzed',
            'path_hash' => hash('sha256', 'g:/unanalyzed'),
            'enabled' => true,
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'title' => 'No vectors',
            'sort_title' => 'No vectors',
            'relative_path' => 'No vectors',
            'relative_path_hash' => hash('sha256', 'no vectors'),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => 'No vectors/track.mp3',
            'relative_path_hash' => hash('sha256', 'no vectors/track.mp3'),
            'file_size' => 100,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Not analyzed',
            'sort_title' => 'Not analyzed',
        ]);

        $this->getJson('/api/settings/audio-intelligence/evaluation')->assertStatus(409);

        ApplicationSetting::current()->update(['audio_intelligence_enabled' => true]);
        $this->getJson("/api/settings/audio-intelligence/evaluation/{$track->id}")
            ->assertNotFound();

        $this->putJson(
            "/api/settings/audio-intelligence/evaluation/{$track->id}"
                ."/matches/{$track->id}/feedback",
            ['verdict' => 'maybe'],
        )->assertUnprocessable();
    }

    /**
     * @param  list<float>  $embedding
     * @param  array<string, mixed>  $features
     */
    private function createAnalyzedTrack(
        AudioAnalysisRun $run,
        int $rootId,
        Album $album,
        Artist $artist,
        string $title,
        array $embedding,
        array $features,
        int $position,
    ): Track {
        $relativePath = "Evaluator/Vectors/{$title}.mp3";
        $fingerprint = hash('sha256', $relativePath);
        $mediaFile = MediaFile::create([
            'library_root_id' => $rootId,
            'album_id' => $album->id,
            'relative_path' => $relativePath,
            'relative_path_hash' => hash('sha256', mb_strtolower($relativePath)),
            'file_size' => 100,
            'modified_at' => now(),
            'last_seen_at' => now(),
            'content_fingerprint' => $fingerprint,
            'content_fingerprint_version' => 1,
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $title,
            'sort_title' => $title,
            'track_number' => $position + 1,
            'duration_ms' => 123000,
            'year' => 2026,
        ]);
        $track->artists()->attach($artist->id, ['role' => 'primary', 'position' => 0]);
        $artifact = AudioAnalysisArtifact::create([
            'audio_analysis_profile_id' => $run->audio_analysis_profile_id,
            'content_fingerprint' => $fingerprint,
            'content_fingerprint_version' => 1,
            'features' => $features,
            'embedding' => $embedding,
        ]);
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
}
