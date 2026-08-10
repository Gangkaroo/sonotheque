<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\AudioAnalysisArtifact;
use App\Models\AudioAnalysisProfile;
use App\Models\AudioAnalysisRun;
use App\Models\AudioSimilarityFeedback;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use App\Music\Intelligence\AnalyzerProfile;
use App\Music\Intelligence\AudioAnalysisProfileSelector;
use App\Music\Intelligence\AudioAnalysisProfileRegistry;
use App\Music\Intelligence\AudioVectorIndex;
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
            'embedding_dimensions' => AudioVectorIndex::DIMENSIONS,
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
            $this->embedding(1.0, 0.0),
            ['bpm' => 120.0, 'key' => 'C'],
            0,
        );
        $near = $this->createAnalyzedTrack(
            $run,
            $root->id,
            $album,
            $artist,
            'Near',
            $this->embedding(0.9, 0.1),
            ['bpm' => 121.0, 'key' => 'C'],
            1,
        );
        $far = $this->createAnalyzedTrack(
            $run,
            $root->id,
            $album,
            $artist,
            'Far',
            $this->embedding(0.0, 1.0),
            ['bpm' => 80.0, 'key' => 'G'],
            2,
        );

        $this->getJson('/api/settings/audio-intelligence/evaluation')
            ->assertOk()
            ->assertJsonPath('analyzedTrackCount', 3)
            ->assertJsonPath('profile.embeddingDimensions', AudioVectorIndex::DIMENSIONS)
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
            ->assertJsonPath('ranking.method', 'embedding')
            ->assertJsonPath('ranking.candidatePoolSize', 2)
            ->assertJsonPath('matches.0.id', $near->id)
            ->assertJsonPath('matches.1.id', $far->id)
            ->assertJsonPath('matches.0.features.bpm', 121)
            ->assertJsonPath('matches.0.rankingScore', $response->json('matches.0.similarity'))
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

        ApplicationSetting::current()->update([
            'audio_similarity_reranking_enabled' => true,
            'audio_similarity_tempo_influence' => 10,
            'audio_similarity_key_influence' => 0,
            'audio_similarity_intensity_influence' => 0,
        ]);
        $this->getJson("/api/settings/audio-intelligence/evaluation/{$source->id}?limit=2")
            ->assertOk()
            ->assertJsonPath('ranking.method', 'feature_reranking')
            ->assertJsonPath('ranking.preferences.tempoInfluence', 10)
            ->assertJsonStructure([
                'matches' => [[
                    'similarity',
                    'rankingScore',
                    'featureCompatibility' => ['tempo', 'key', 'intensity'],
                ]],
            ]);

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

        $tracks = [$source, $near, $far];
        $configurations = ['all', 'exclude_album', 'exclude_artist', 'exclude_album_artist'];
        $ratingIndex = 0;
        foreach ($tracks as $ratedSource) {
            foreach ($tracks as $candidate) {
                if ($ratedSource->is($candidate)) {
                    continue;
                }
                foreach ($configurations as $configuration) {
                    AudioSimilarityFeedback::create([
                        'audio_analysis_profile_id' => $profile->id,
                        'source_track_id' => $ratedSource->id,
                        'candidate_track_id' => $candidate->id,
                        'configuration' => $configuration,
                        'verdict' => $ratingIndex++ % 2 === 0 ? 'relevant' : 'irrelevant',
                    ]);
                }
            }
        }

        $this->postJson('/api/settings/audio-intelligence/personalization/train')
            ->assertOk()
            ->assertJsonPath('personalization.ready', true)
            ->assertJsonPath('personalization.canTrain', true)
            ->assertJsonPath('personalization.feedbackCount', 24)
            ->assertJsonPath('personalization.relevantCount', 12)
            ->assertJsonPath('personalization.irrelevantCount', 12);
        $this->assertDatabaseCount('audio_similarity_personalizations', 1);

        $this->patchJson('/api/settings/audio-intelligence', [
            'enabled' => true,
            'validationSampleSize' => 200,
            'personalization' => ['enabled' => true],
        ])->assertOk()
            ->assertJsonPath('personalization.enabled', true)
            ->assertJsonPath('personalization.applied', true);
        $this->getJson("/api/settings/audio-intelligence/evaluation/{$source->id}?limit=2")
            ->assertOk()
            ->assertJsonPath('ranking.method', 'personalized')
            ->assertJsonPath('ranking.personalization.applied', true);

        $this->deleteJson('/api/settings/audio-intelligence/personalization')
            ->assertOk()
            ->assertJsonPath('personalization.enabled', false)
            ->assertJsonPath('personalization.ready', false);
        $this->assertDatabaseCount('audio_similarity_personalizations', 0);
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

    public function test_model_upgrade_keeps_the_best_covered_profile_active_until_it_catches_up(): void
    {
        ApplicationSetting::current()->update(['audio_intelligence_enabled' => true]);
        $library = Library::create(['name' => 'Profile upgrade']);
        $root = $library->roots()->create([
            'name' => 'Root',
            'path' => 'G:/Profile-upgrade',
            'path_hash' => hash('sha256', 'g:/profile-upgrade'),
            'enabled' => true,
        ]);
        $artist = Artist::create(['name' => 'Upgrade artist', 'sort_name' => 'Upgrade artist']);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Upgrade album',
            'sort_title' => 'Upgrade album',
            'relative_path' => 'Upgrade artist/Upgrade album',
            'relative_path_hash' => hash('sha256', 'upgrade artist/upgrade album'),
        ]);
        $oldProfile = $this->resolveProfile('1', str_repeat('a', 64));
        $oldRun = AudioAnalysisRun::create([
            'audio_analysis_profile_id' => $oldProfile->id,
            'status' => 'completed',
            'selection_seed' => fake()->uuid(),
            'requested_track_count' => 3,
            'selected_track_count' => 3,
        ]);
        $source = $this->createAnalyzedTrack(
            $oldRun,
            $root->id,
            $album,
            $artist,
            'Source',
            $this->embedding(1.0, 0.0),
            [],
            0,
        );
        $near = $this->createAnalyzedTrack(
            $oldRun,
            $root->id,
            $album,
            $artist,
            'Near',
            $this->embedding(0.9, 0.1),
            [],
            1,
        );
        $far = $this->createAnalyzedTrack(
            $oldRun,
            $root->id,
            $album,
            $artist,
            'Far',
            $this->embedding(0.0, 1.0),
            [],
            2,
        );

        $newProfile = $this->resolveProfile('2', str_repeat('b', 64));
        $newRun = AudioAnalysisRun::create([
            'audio_analysis_profile_id' => $newProfile->id,
            'status' => 'running',
            'selection_seed' => fake()->uuid(),
            'requested_track_count' => 3,
            'selected_track_count' => 3,
        ]);
        $this->attachAnalyzedTrack(
            $newRun,
            $source,
            $this->embedding(1.0, 0.0),
            [],
            0,
        );

        $this->getJson('/api/settings/audio-intelligence/evaluation')
            ->assertOk()
            ->assertJsonPath('profile.modelVersion', '1')
            ->assertJsonPath('analyzedTrackCount', 3);

        $this->attachAnalyzedTrack(
            $newRun,
            $near,
            $this->embedding(0.8, 0.2),
            [],
            1,
        );
        $this->attachAnalyzedTrack(
            $newRun,
            $far,
            $this->embedding(0.1, 0.9),
            [],
            2,
        );
        $newRun->update(['status' => 'completed', 'finished_at' => now()]);
        app(AudioAnalysisProfileSelector::class)->forget();

        $this->getJson('/api/settings/audio-intelligence/evaluation')
            ->assertOk()
            ->assertJsonPath('profile.modelVersion', '2')
            ->assertJsonPath('analyzedTrackCount', 3);
        $this->assertSame(3, $oldProfile->artifacts()->count());
        $this->assertSame(3, $newProfile->artifacts()->count());
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
        $this->attachAnalyzedTrack($run, $track, $embedding, $features, $position);

        return $track;
    }

    /**
     * @param  list<float>  $embedding
     * @param  array<string, mixed>  $features
     */
    private function attachAnalyzedTrack(
        AudioAnalysisRun $run,
        Track $track,
        array $embedding,
        array $features,
        int $position,
    ): void {
        $fingerprint = $track->mediaFile->content_fingerprint;
        $artifact = AudioAnalysisArtifact::create([
            'audio_analysis_profile_id' => $run->audio_analysis_profile_id,
            'content_fingerprint' => $fingerprint,
            'content_fingerprint_version' => 1,
            'features' => $features,
            'embedding' => $embedding,
        ]);
        app(AudioVectorIndex::class)->synchronize($artifact, $embedding);
        $run->items()->create([
            'track_id' => $track->id,
            'library_root_id' => $track->mediaFile->library_root_id,
            'audio_analysis_artifact_id' => $artifact->id,
            'content_fingerprint' => $fingerprint,
            'content_fingerprint_version' => 1,
            'position' => $position,
            'status' => 'completed',
        ]);
    }

    private function resolveProfile(string $modelVersion, string $checksum): AudioAnalysisProfile
    {
        return app(AudioAnalysisProfileRegistry::class)->resolve(new AnalyzerProfile(
            key: 'profile-upgrade',
            protocolVersion: 1,
            analyzerName: 'Test analyzer',
            analyzerVersion: '1',
            analyzerLicense: 'Test license',
            modelName: 'Test model',
            modelVersion: $modelVersion,
            modelChecksum: $checksum,
            modelLicense: 'Test model license',
            embeddingDimensions: AudioVectorIndex::DIMENSIONS,
            sampleRate: 16000,
        ));
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
