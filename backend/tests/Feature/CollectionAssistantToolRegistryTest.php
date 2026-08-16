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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
