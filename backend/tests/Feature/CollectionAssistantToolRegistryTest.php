<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use App\Models\TrackPlayEvent;
use App\Models\TrackPlayStatistic;
use App\Music\Assistant\CollectionAssistantToolException;
use App\Music\Assistant\CollectionAssistantToolRegistry;
use App\Music\Intelligence\AudioSimilarityEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class CollectionAssistantToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_and_search_respect_the_active_library_root(): void
    {
        [$firstRoot, $firstTrack] = $this->createTrack(
            'First root',
            'D:/First',
            'The Cure',
            'Disintegration',
            'Pictures of You',
        );
        $firstTrack->genres()->attach(Genre::create(['name' => 'Alternative Rock']));
        $this->createTrack(
            'Second root',
            'E:/Second',
            'The Cure Tribute',
            'Other Album',
            'Other Track',
        );
        $tools = app(CollectionAssistantToolRegistry::class);

        $summary = $tools->execute('collection_summary', [
            'metrics' => ['artists', 'albums', 'tracks'],
        ], $firstRoot->id);
        $search = $tools->execute('search_catalog', [
            'query' => 'Cure',
            'entity_types' => ['artists', 'albums', 'tracks'],
            'artist_name' => 'The Cure',
            'limit' => 10,
        ], $firstRoot->id);
        $artistAlbums = $tools->execute('search_albums_by_artist', [
            'artist_name' => 'The Cure',
            'limit' => 10,
        ], $firstRoot->id);

        $this->assertSame('First root', $summary['scope']['name']);
        $this->assertSame(1, $summary['counts']['artists']);
        $this->assertSame(1, $summary['counts']['albums']);
        $this->assertSame(1, $summary['counts']['tracks']);
        $this->assertSame(['The Cure'], array_column($search['results']['artists'], 'name'));
        $this->assertSame(['Disintegration'], array_column($search['results']['albums'], 'title'));
        $this->assertSame(['Disintegration'], array_column($artistAlbums['results'], 'title'));
        $this->assertSame(['Pictures of You'], array_column($search['results']['tracks'], 'title'));
        $this->assertArrayNotHasKey('relativePath', $search['results']['tracks'][0]);
        $this->assertSame('/tracks/'.$firstTrack->id, $search['results']['tracks'][0]['path']);
    }

    public function test_search_rejects_unknown_or_unbounded_arguments(): void
    {
        $tools = app(CollectionAssistantToolRegistry::class);

        try {
            $tools->execute('search_catalog', [
                'query' => 'Cure',
                'limit' => 1000,
                'sql' => 'select * from tracks',
            ], null);
            $this->fail('Invalid tool arguments were accepted.');
        } catch (CollectionAssistantToolException $exception) {
            $this->assertSame('invalid_arguments', $exception->errorCode);
        }
    }

    public function test_similarity_tool_uses_the_active_root_and_returns_explainable_matches(): void
    {
        [$root, $source] = $this->createTrack(
            'Similarity root',
            'D:/Similarity',
            'Source Artist',
            'Source Album',
            'Source Track',
        );
        [, $match] = $this->createTrack(
            'Similarity root',
            'D:/Similarity',
            'Match Artist',
            'Match Album',
            'Match Track',
            $root,
        );
        \App\Models\ApplicationSetting::current()->update([
            'audio_intelligence_enabled' => true,
        ]);
        $evaluator = Mockery::mock(AudioSimilarityEvaluator::class);
        $evaluator->shouldReceive('evaluate')
            ->once()
            ->withArgs(fn (...$arguments): bool => $arguments[0] === $source->id
                && $arguments[1] === 3
                && $arguments[2] === true
                && $arguments[3] === true
                && $arguments[6] === $root->id)
            ->andReturn([
                'source' => [
                    'id' => $source->id,
                    'title' => $source->title,
                    'artistName' => 'Source Artist',
                    'albumId' => $source->album_id,
                    'albumTitle' => 'Source Album',
                    'libraryRootName' => $root->name,
                    'features' => ['bpm' => 120, 'key' => 'C'],
                ],
                'candidateCount' => 20,
                'calculationMs' => 2.5,
                'ranking' => ['method' => 'feature_reranking'],
                'matches' => [[
                    'id' => $match->id,
                    'title' => $match->title,
                    'artistName' => 'Match Artist',
                    'albumId' => $match->album_id,
                    'albumTitle' => 'Match Album',
                    'libraryRootName' => $root->name,
                    'similarity' => 0.91,
                    'rankingScore' => 0.87,
                    'featureCompatibility' => ['tempo' => 0.95],
                    'features' => ['bpm' => 122, 'key' => 'C'],
                ]],
            ]);
        $this->app->instance(AudioSimilarityEvaluator::class, $evaluator);

        $result = app(CollectionAssistantToolRegistry::class)->execute(
            'find_similar_tracks',
            [
                'title' => 'Source Track',
                'artist_name' => 'Source Artist',
                'limit' => 3,
                'action' => 'play',
            ],
            $root->id,
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame('Similarity root', $result['scope']['name']);
        $this->assertSame('feature_reranking', $result['basis']['rankingMethod']);
        $this->assertSame(2, $result['coverage']['totalTrackCount']);
        $this->assertSame(0, $result['coverage']['analyzedTrackCount']);
        $this->assertSame('/tracks/'.$source->id, $result['reference']['path']);
        $this->assertSame('/tracks/'.$match->id, $result['results'][0]['path']);
        $this->assertSame(0.87, $result['results'][0]['rankingScore']);
        $this->assertSame('track_queue', $result['action']['type']);
        $this->assertSame('play', $result['action']['mode']);
        $this->assertSame(
            [$source->id, $match->id],
            array_column($result['action']['tracks'], 'id'),
        );
        $this->assertSame(
            "/api/tracks/{$source->id}/stream",
            $result['action']['tracks'][0]['streamUrl'],
        );
    }

    public function test_similarity_tool_reports_ambiguous_disabled_and_unanalyzed_references(): void
    {
        [$root, $first] = $this->createTrack(
            'Ambiguous root',
            'D:/Ambiguous',
            'First Artist',
            'First Album',
            'Shared Title',
        );
        [, $second] = $this->createTrack(
            'Ambiguous root',
            'D:/Ambiguous',
            'Second Artist',
            'Second Album',
            'Shared Title',
            $root,
        );
        $tools = app(CollectionAssistantToolRegistry::class);

        $ambiguous = $tools->execute('find_similar_tracks', [
            'title' => 'Shared Title',
        ], $root->id);
        $disabled = $tools->execute('find_similar_tracks', [
            'title' => 'Shared Title',
            'artist_name' => 'First Artist',
        ], $root->id);
        \App\Models\ApplicationSetting::current()->update([
            'audio_intelligence_enabled' => true,
        ]);
        $notAnalyzed = $tools->execute('find_similar_tracks', [
            'title' => 'Shared Title',
            'artist_name' => 'First Artist',
        ], $root->id);

        $this->assertSame('ambiguous_reference', $ambiguous['status']);
        $this->assertSame(
            [$first->id, $second->id],
            array_column($ambiguous['candidates'], 'id'),
        );
        $this->assertSame('audio_intelligence_disabled', $disabled['status']);
        $this->assertSame('reference_not_analyzed', $notAnalyzed['status']);
        $this->assertSame($first->id, $notAnalyzed['reference']['id']);
    }

    public function test_listening_tools_use_aggregate_counts_and_timestamped_events_with_root_scope(): void
    {
        Carbon::setTestNow('2026-08-16 12:00:00+00');
        [$root, $playedTrack] = $this->createTrack(
            'Listening root',
            'D:/Listening',
            'Played Artist',
            'Played Album',
            'Played Track',
        );
        [, $unplayedTrack] = $this->createTrack(
            'Listening root',
            'D:/Listening',
            'Unplayed Artist',
            'Unplayed Album',
            'Unplayed Track',
            $root,
        );
        [, $otherTrack] = $this->createTrack(
            'Other root',
            'E:/Other',
            'Other Artist',
            'Other Album',
            'Other Track',
        );
        $playedTrack->genres()->attach(Genre::create(['name' => 'Played Genre']));
        $otherTrack->genres()->attach(Genre::create(['name' => 'Other Genre']));
        TrackPlayStatistic::create([
            'track_id' => $playedTrack->id,
            'play_count' => 5,
            'first_played_at' => now()->subDays(40),
            'last_played_at' => now()->subDays(2),
        ]);
        TrackPlayStatistic::create([
            'track_id' => $otherTrack->id,
            'play_count' => 100,
            'first_played_at' => now()->subDays(2),
            'last_played_at' => now()->subDay(),
        ]);
        TrackPlayEvent::create([
            'track_id' => $playedTrack->id,
            'played_at' => now()->subDays(2),
            'counted' => true,
        ]);
        TrackPlayEvent::create([
            'track_id' => $playedTrack->id,
            'played_at' => now()->subDays(40),
            'counted' => true,
        ]);
        TrackPlayEvent::create([
            'track_id' => $otherTrack->id,
            'played_at' => now()->subDay(),
            'counted' => true,
        ]);
        $tools = app(CollectionAssistantToolRegistry::class);

        $allTime = $tools->execute('listening_summary', ['period' => 'all_time'], $root->id);
        $lastThirtyDays = $tools->execute('listening_summary', ['period' => '30_days'], $root->id);
        $topTracks = $tools->execute('top_listened', [
            'entity_type' => 'tracks',
            'period' => 'all_time',
        ], $root->id);
        $topAlbums = $tools->execute('top_listened', [
            'entity_type' => 'albums',
            'period' => '30_days',
        ], $root->id);
        $topArtists = $tools->execute('top_listened', [
            'entity_type' => 'artists',
            'period' => 'all_time',
        ], $root->id);
        $topGenres = $tools->execute('top_listened', [
            'entity_type' => 'genres',
            'period' => 'all_time',
        ], $root->id);
        $recentGenres = $tools->execute('top_listened', [
            'entity_type' => 'genres',
            'period' => '30_days',
        ], $root->id);
        $recent = $tools->execute('recent_listening_history', ['limit' => 1], $root->id);
        $unplayed = $tools->execute('find_unplayed_albums', ['limit' => 5], $root->id);

        $this->assertSame([
            'plays' => 5,
            'tracks' => 1,
            'albums' => 1,
        ], $allTime['counts']);
        $this->assertSame('aggregate_play_statistics', $allTime['period']['basis']);
        $this->assertSame([
            'plays' => 1,
            'tracks' => 1,
            'albums' => 1,
        ], $lastThirtyDays['counts']);
        $this->assertSame('counted_play_events', $lastThirtyDays['period']['basis']);
        $this->assertSame(['Played Track'], array_column($topTracks['results'], 'title'));
        $this->assertSame(['Played Album'], array_column($topAlbums['results'], 'title'));
        $this->assertSame(['Played Artist'], array_column($topArtists['results'], 'name'));
        $this->assertSame(['Played Genre'], array_column($topGenres['results'], 'name'));
        $this->assertSame(5, $topGenres['results'][0]['playCount']);
        $this->assertSame(['Played Genre'], array_column($recentGenres['results'], 'name'));
        $this->assertSame(1, $recentGenres['results'][0]['playCount']);
        $this->assertSame($playedTrack->id, $recent['results'][0]['id']);
        $this->assertSame([$unplayedTrack->album_id], array_column($unplayed['results'], 'id'));
    }

    /** @return array{0: \App\Models\LibraryRoot, 1: Track} */
    private function createTrack(
        string $rootName,
        string $rootPath,
        string $artistName,
        string $albumTitle,
        string $trackTitle,
        ?\App\Models\LibraryRoot $root = null,
    ): array {
        $artist = Artist::create([
            'name' => $artistName,
            'sort_name' => $artistName,
            'browse_initial' => 'T',
        ]);
        $root ??= Library::firstOrCreate(['name' => 'Assistant test'])->roots()->create([
            'name' => $rootName,
            'path' => $rootPath,
            'path_hash' => hash('sha256', mb_strtolower($rootPath)),
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => $albumTitle,
            'sort_title' => $albumTitle,
            'relative_path' => $artistName.'/'.$albumTitle,
            'relative_path_hash' => hash('sha256', $artistName.'/'.$albumTitle),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => $artistName.'/'.$albumTitle.'/'.$trackTitle.'.mp3',
            'relative_path_hash' => hash('sha256', $artistName.'/'.$albumTitle.'/'.$trackTitle.'.mp3'),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => $trackTitle,
            'sort_title' => $trackTitle,
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        return [$root, $track];
    }
}
