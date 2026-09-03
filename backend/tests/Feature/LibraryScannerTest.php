<?php

namespace Tests\Feature;

use App\Enums\ArtworkSource;
use App\Enums\MediaFileStatus;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanLibraryRoot;
use App\Models\Album;
use App\Models\AlbumRecordLabel;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Artwork;
use App\Models\Genre;
use App\Models\FavoriteTrack;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\RecordLabel;
use App\Models\ScanRun;
use App\Models\ScanRunIssue;
use App\Models\Track;
use App\Models\TrackPlayStatistic;
use App\Music\Artwork\EmbeddedArtwork;
use App\Music\Catalog\RecordLabelTagReader;
use App\Music\Scanning\AudioFileDiscoverer;
use App\Music\Scanning\AudioContentFingerprinter;
use App\Music\Scanning\AudioMetadata;
use App\Music\Scanning\AudioMetadataReader;
use App\Music\Scanning\DiscoveryDiagnostics;
use App\Music\Scanning\LibraryScanner;
use App\Music\Scanning\ScanMediaFileState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Fakes\FakeAudioMetadataReader;
use Tests\Fakes\FakeAudioContentFingerprinter;
use Tests\TestCase;

class LibraryScannerTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    private FakeAudioMetadataReader $metadataReader;

    private FakeAudioContentFingerprinter $contentFingerprinter;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artwork');
        $this->musicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sonotheque-'.Str::uuid();
        mkdir($this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut', recursive: true);

        $this->metadataReader = new FakeAudioMetadataReader(new AudioMetadata(
            title: 'Human Behaviour',
            album: 'Debut',
            albumArtist: 'Björk',
            artists: ['Björk'],
            composers: ['Nellee Hooper'],
            performers: ['Björk'],
            comment: 'Album version',
            genres: ['Electronic'],
            year: 1993,
            originalReleaseYear: 1993,
            trackNumber: 1,
            discNumber: 1,
            discTotal: 1,
            durationMs: 252000,
            mimeType: 'audio/mpeg',
            container: 'mp3',
            codec: 'mp3',
            bitrate: 320000,
            sampleRate: 44100,
            channels: 2,
            warnings: ['Test warning'],
            rawMetadata: ['fileformat' => 'mp3'],
        ));

        $this->app->instance(AudioMetadataReader::class, $this->metadataReader);
        $this->contentFingerprinter = new FakeAudioContentFingerprinter();
        $this->app->instance(AudioContentFingerprinter::class, $this->contentFingerprinter);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->removeDirectory($this->musicPath);

        parent::tearDown();
    }

    public function test_scanner_imports_metadata_and_skips_unchanged_files(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01 - Human Behaviour.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $root = $this->createRoot();

        $firstScan = $this->createScan($root);
        $scanner = $this->app->make(LibraryScanner::class);
        $scanner->scan($firstScan);

        $this->assertSame(ScanStatus::Completed, $firstScan->fresh()->status);
        $this->assertSame(1, $firstScan->fresh()->files_added);
        $this->assertSame(1, $firstScan->fresh()->warning_count);
        $this->assertSame(1, $this->metadataReader->calls);
        $this->assertDatabaseHas(Artist::class, ['name' => 'Björk', 'browse_initial' => 'B']);
        $this->assertDatabaseHas(Album::class, ['title' => 'Debut', 'original_release_year' => 1993]);
        $this->assertDatabaseHas(Track::class, ['title' => 'Human Behaviour', 'track_number' => 1]);
        $track = Track::firstOrFail();
        $this->assertSame(['Nellee Hooper'], $track->composers);
        $this->assertSame(['Björk'], $track->performers);
        $this->assertSame('Album version', $track->comment);
        $this->assertDatabaseHas(Genre::class, ['name' => 'Electronic']);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Bjoerk/Debut/01 - Human Behaviour.mp3',
            'status' => MediaFileStatus::Available->value,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $secondScan = $this->createScan($root);
        $scanner->scan($secondScan);

        $cache = (new \ReflectionProperty($scanner, 'existingFiles'))->getValue($scanner);
        $cachedFile = array_values($cache)[0];
        $this->assertInstanceOf(ScanMediaFileState::class, $cachedFile);
        $this->assertSame(MediaFileStatus::Available, $cachedFile->status);
        $this->assertSame(LibraryScanner::METADATA_PARSER_VERSION, $cachedFile->metadataParserVersion);

        $this->assertSame(ScanStatus::Completed, $secondScan->fresh()->status);
        $this->assertSame(0, $secondScan->fresh()->files_added);
        $this->assertSame(0, $secondScan->fresh()->files_updated);
        $this->assertSame(1, $secondScan->fresh()->files_processed);
        $this->assertSame(1, $this->metadataReader->calls);
    }

    public function test_scanner_does_not_backfill_a_missing_fingerprint_for_an_unchanged_file(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $root = $this->createRoot();
        $scanner = $this->app->make(LibraryScanner::class);

        $scanner->scan($this->createScan($root));
        $this->assertSame(1, $this->contentFingerprinter->calls);

        MediaFile::query()->update([
            'content_fingerprint' => null,
            'content_fingerprint_version' => null,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $scan = $this->createScan($root);
        $scanner->scan($scan);

        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status);
        $this->assertSame(0, $scan->fresh()->files_updated);
        $this->assertSame(1, $this->contentFingerprinter->calls);
        $this->assertNull(MediaFile::sole()->content_fingerprint);
    }

    public function test_scanner_persists_issue_details_beyond_the_summary_limit(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';

        foreach (range(1, 55) as $number) {
            file_put_contents(
                $albumPath.DIRECTORY_SEPARATOR.sprintf('%02d - Track.mp3', $number),
                'fake audio data '.$number,
            );
        }

        $scan = $this->createScan($this->createRoot());
        $this->app->make(LibraryScanner::class)->scan($scan);
        $scan->refresh();

        $this->assertSame(55, $scan->warning_count);
        $this->assertCount(50, $scan->summary['issues']);
        $this->assertTrue($scan->summary['issuesTruncated']);
        $this->assertSame(55, ScanRunIssue::query()->whereBelongsTo($scan)->count());
    }

    public function test_scanner_reprocesses_unchanged_files_after_a_metadata_parser_upgrade(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $root = $this->createRoot();
        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));

        $legacyGenre = Genre::create(['name' => '(137)Heavy Metal']);
        Track::firstOrFail()->genres()->sync([$legacyGenre->id]);
        MediaFile::firstOrFail()->update(['metadata_parser_version' => 2]);

        $upgradedReader = new FakeAudioMetadataReader(new AudioMetadata(
            title: 'Human Behaviour',
            album: 'Debut',
            albumArtist: 'BjÃ¶rk',
            artists: ['BjÃ¶rk'],
            genres: ['Heavy Metal'],
        ));
        $this->app->instance(AudioMetadataReader::class, $upgradedReader);
        $scan = $this->createScan($root);

        $this->app->make(LibraryScanner::class)->scan($scan);

        $this->assertSame(1, $upgradedReader->calls);
        $this->assertSame(1, $scan->fresh()->files_updated);
        $this->assertSame(LibraryScanner::METADATA_PARSER_VERSION, MediaFile::firstOrFail()->metadata_parser_version);
        $this->assertDatabaseHas(Genre::class, ['name' => 'Heavy Metal']);
        $this->assertDatabaseMissing(Genre::class, ['name' => '(137)Heavy Metal']);
    }

    public function test_scanner_imports_tag_statistics_from_cached_metadata_once_enabled(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $this->metadataReader = new FakeAudioMetadataReader(new AudioMetadata(
            title: 'Human Behaviour',
            album: 'Debut',
            albumArtist: 'BjÃ¶rk',
            artists: ['BjÃ¶rk'],
            rawMetadata: [
                'comments' => [
                    'PLAY_COUNT' => ['7'],
                    'FIRST_PLAYED_TIMESTAMP' => ['2020-01-02 03:04:05'],
                    'LAST_PLAYED_TIMESTAMP' => ['2021-02-03 04:05:06'],
                ],
            ],
        ));
        $this->app->instance(AudioMetadataReader::class, $this->metadataReader);
        $root = $this->createRoot();
        $scanner = $this->app->make(LibraryScanner::class);
        $scanner->scan($this->createScan($root));
        $track = Track::firstOrFail();
        TrackPlayStatistic::create([
            'track_id' => $track->id,
            'play_count' => 10,
            'first_played_at' => '2020-06-01 00:00:00+00',
            'last_played_at' => '2020-07-01 00:00:00+00',
        ]);
        ApplicationSetting::current()->update(['import_play_statistics_from_tags' => true]);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $importScan = $this->createScan($root);
        $scanner->scan($importScan);
        $statistics = $track->playStatistic()->firstOrFail();

        $this->assertSame(1, $this->metadataReader->calls);
        $this->assertSame(10, $statistics->play_count);
        $this->assertSame('2020-01-02T03:04:05.000000Z', $statistics->first_played_at->toJSON());
        $this->assertSame('2021-02-03T04:05:06.000000Z', $statistics->last_played_at->toJSON());
        $this->assertSame(7, $statistics->source_metadata['file_tags']['play_count']);
        $this->assertSame(1, $importScan->fresh()->summary['playStatisticsImported']);
        $this->assertSame(1, MediaFile::firstOrFail()->play_statistics_import_version);

        MediaFile::firstOrFail()->update([
            'raw_metadata' => [
                'comments' => [
                    'PLAY_COUNT' => ['999'],
                ],
            ],
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $repeatScan = $this->createScan($root);
        $scanner->scan($repeatScan);

        $this->assertSame(1, $this->metadataReader->calls);
        $this->assertArrayNotHasKey('playStatisticsImported', $repeatScan->fresh()->summary);
        $this->assertSame(10, $track->playStatistic()->firstOrFail()->play_count);
    }

    public function test_scanner_imports_ratings_from_cached_metadata_once_enabled(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $this->metadataReader = new FakeAudioMetadataReader(new AudioMetadata(
            title: 'Human Behaviour',
            album: 'Debut',
            albumArtist: 'Björk',
            artists: ['Björk'],
            rawMetadata: [
                'id3v2' => [
                    'POPM' => [[
                        'email' => 'Windows Media Player 9 Series',
                        'rating' => 196,
                    ]],
                    'TXXX' => [[
                        'description' => 'SONOTHEQUE_ALBUM_RATING',
                        'data' => '4.5',
                    ]],
                ],
            ],
        ));
        $this->app->instance(AudioMetadataReader::class, $this->metadataReader);
        $root = $this->createRoot();
        $scanner = $this->app->make(LibraryScanner::class);
        $scanner->scan($this->createScan($root));
        ApplicationSetting::current()->update(['synchronize_ratings_with_tags' => true]);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $importScan = $this->createScan($root);
        $scanner->scan($importScan);

        $this->assertSame(1, $this->metadataReader->calls);
        $this->assertSame(8, Track::sole()->rating_half_steps);
        $this->assertSame(9, Album::sole()->rating_half_steps);
        $this->assertSame(2, $importScan->fresh()->summary['ratingsImported']);
        $this->assertSame(1, MediaFile::sole()->rating_tags_import_version);
    }

    public function test_scanner_imports_record_labels_and_refreshes_them_from_cached_metadata(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $this->metadataReader = new FakeAudioMetadataReader(new AudioMetadata(
            title: 'Human Behaviour',
            album: 'Debut',
            albumArtist: 'Björk',
            artists: ['Björk'],
            rawMetadata: [
                'comments' => [
                    'publisher' => ['One Little Indian Records'],
                    'catalog_number' => ['TPLP31CD'],
                ],
            ],
        ));
        $this->app->instance(AudioMetadataReader::class, $this->metadataReader);
        $root = $this->createRoot();
        $scanner = $this->app->make(LibraryScanner::class);
        $firstScan = $this->createScan($root);

        $scanner->scan($firstScan);

        $this->assertSame('One Little Indian Records', RecordLabel::sole()->name);
        $this->assertDatabaseHas(AlbumRecordLabel::class, [
            'album_id' => Album::sole()->id,
            'catalog_number' => 'TPLP31CD',
            'source' => 'file_tag',
        ]);
        $this->assertSame(
            RecordLabelTagReader::IMPORT_VERSION,
            MediaFile::sole()->record_label_tags_import_version,
        );
        $this->assertSame(1, $firstScan->fresh()->summary['recordLabelChanges']);

        MediaFile::sole()->update([
            'raw_metadata' => [
                'comments' => [
                    'LABEL' => ['One Little Independent Records'],
                    'CATALOGNUMBER' => ['TPLP31CD'],
                ],
            ],
            'record_label_tags_import_version' => null,
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $secondScan = $this->createScan($root);

        $scanner->scan($secondScan);

        $this->assertSame(1, $this->metadataReader->calls);
        $this->assertSame('One Little Independent Records', RecordLabel::sole()->name);
        $this->assertSame('TPLP31CD', AlbumRecordLabel::sole()->catalog_number);
        $this->assertSame(2, $secondScan->fresh()->summary['recordLabelChanges']);
    }

    public function test_incremental_scan_batches_progress_and_cancellation_queries(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';

        foreach (range(1, 30) as $trackNumber) {
            file_put_contents($albumPath.DIRECTORY_SEPARATOR.sprintf('%02d.mp3', $trackNumber), 'fake audio data');
        }

        $root = $this->createRoot();
        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $scanRunQueries = 0;
        DB::listen(function ($query) use (&$scanRunQueries): void {
            if (str_contains(mb_strtolower($query->sql), 'scan_runs')) {
                $scanRunQueries++;
            }
        });

        $scan = $this->createScan($root);
        $this->app->make(LibraryScanner::class)->scan($scan);

        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status);
        $this->assertSame(30, $scan->fresh()->files_processed);
        $this->assertSame(29, $scan->fresh()->summary['unchangedFilesFastTracked']);
        $this->assertLessThanOrEqual(11, $scanRunQueries);
        $this->assertSame(30, $this->metadataReader->calls);
    }

    public function test_scanner_counts_all_files_before_reading_metadata(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';

        foreach (range(1, 3) as $trackNumber) {
            file_put_contents($albumPath.DIRECTORY_SEPARATOR.sprintf('%02d.mp3', $trackNumber), 'fake audio data');
        }

        $scan = $this->createScan($this->createRoot());
        $observedProgress = null;
        $this->metadataReader->beforeRead = function () use ($scan, &$observedProgress): void {
            $freshScan = $scan->fresh();
            $observedProgress = [
                'discovered' => $freshScan->files_discovered,
                'processed' => $freshScan->files_processed,
                'phase' => $freshScan->summary['phase'],
            ];
            $this->metadataReader->beforeRead = null;
        };

        $this->app->make(LibraryScanner::class)->scan($scan);

        $this->assertSame([
            'discovered' => 3,
            'processed' => 0,
            'phase' => 'scanning',
        ], $observedProgress);
    }

    public function test_scanner_reuses_the_discovery_manifest_instead_of_walking_the_root_twice(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $delegate = $this->app->make(AudioFileDiscoverer::class);
        $discoverer = new class ($delegate) extends AudioFileDiscoverer {
            public int $calls = 0;

            public function __construct(private readonly AudioFileDiscoverer $delegate)
            {
            }

            /** @return \Generator<int, \App\Music\Scanning\DiscoveredAudioFile> */
            public function discover(
                LibraryRoot $libraryRoot,
                ?DiscoveryDiagnostics $diagnostics = null,
                ?string $subtreePath = null,
            ): \Generator {
                $this->calls++;

                yield from $this->delegate->discover($libraryRoot, $diagnostics, $subtreePath);
            }
        };
        $this->app->instance(AudioFileDiscoverer::class, $discoverer);
        $scan = $this->createScan($this->createRoot());

        $this->app->make(LibraryScanner::class)->scan($scan);

        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status);
        $this->assertSame(1, $discoverer->calls);
        $this->assertSame(1, $scan->fresh()->files_processed);
    }

    public function test_scanner_marks_missing_files_unavailable_without_deleting_tracks(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        $remainingTrackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'02.mp3';
        file_put_contents($trackPath, 'fake audio data');
        file_put_contents($remainingTrackPath, 'fake audio data');
        $root = $this->createRoot();

        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));
        unlink($trackPath);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());

        $scan = $this->createScan($root);
        $this->app->make(LibraryScanner::class)->scan($scan);

        $this->assertSame(1, $scan->fresh()->files_removed);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Bjoerk/Debut/01.mp3',
            'status' => MediaFileStatus::Missing->value,
        ]);
        $this->assertDatabaseCount(MediaFile::class, 2);
        $this->assertDatabaseCount(Track::class, 2);
        $this->assertSame('files_unavailable', $scan->fresh()->summary['issues'][0]['code']);
    }

    public function test_scanner_preserves_catalog_identity_when_a_file_moves_and_its_id3_tags_change(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        $oldPath = $albumPath.DIRECTORY_SEPARATOR.'01.mp3';
        $newPath = $albumPath.DIRECTORY_SEPARATOR.'03.mp3';
        file_put_contents($oldPath, 'ID3:title=Old|AUDIO|fake audio data');
        $root = $this->createRoot();
        $scanner = $this->app->make(LibraryScanner::class);
        $scanner->scan($this->createScan($root));
        $mediaFile = MediaFile::firstOrFail();
        $track = Track::firstOrFail();
        $playlist = Playlist::create(['name' => 'Preserved playlist']);
        PlaylistItem::create(['playlist_id' => $playlist->id, 'track_id' => $track->id, 'position' => 1]);
        FavoriteTrack::create(['track_id' => $track->id]);
        $originalModifiedAt = filemtime($oldPath);
        $this->assertIsInt($originalModifiedAt);

        rename($oldPath, $newPath);
        file_put_contents($newPath, 'ID3:title=New|AUDIO|fake audio data');
        touch($newPath, $originalModifiedAt);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $scan = $this->createScan($root);
        $scanner->scan($scan);

        $this->assertSame(0, $scan->fresh()->files_added);
        $this->assertSame(1, $scan->fresh()->files_updated);
        $this->assertSame(0, $scan->fresh()->files_removed);
        $this->assertSame(2, $this->metadataReader->calls);
        $this->assertDatabaseMissing(MediaFile::class, ['relative_path' => 'Bjoerk/Debut/01.mp3']);
        $this->assertDatabaseHas(MediaFile::class, [
            'id' => $mediaFile->id,
            'relative_path' => 'Bjoerk/Debut/03.mp3',
        ]);
        $this->assertDatabaseCount(MediaFile::class, 1);
        $this->assertDatabaseCount(Track::class, 1);
        $this->assertDatabaseHas(Track::class, ['id' => $track->id, 'media_file_id' => $mediaFile->id]);
        $this->assertDatabaseHas(PlaylistItem::class, ['playlist_id' => $playlist->id, 'track_id' => $track->id]);
        $this->assertDatabaseHas(FavoriteTrack::class, ['track_id' => $track->id]);
    }

    public function test_scanner_restores_missing_track_and_album_identity_in_another_root(): void
    {
        $sourcePath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($sourcePath, 'ID3:title=Old|AUDIO|same audio data');
        $sourceRoot = $this->createRoot();
        $scanner = $this->app->make(LibraryScanner::class);
        $scanner->scan($this->createScan($sourceRoot));
        $mediaFile = MediaFile::firstOrFail();
        $track = Track::firstOrFail();
        $album = Album::firstOrFail();
        $playlist = Playlist::create(['name' => 'Cross-root move']);
        PlaylistItem::create([
            'playlist_id' => $playlist->id,
            'track_id' => $track->id,
            'position' => 0,
        ]);

        $destinationPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sonotheque-destination-'.Str::uuid();
        $destinationAlbumPath = $destinationPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        mkdir($destinationAlbumPath, recursive: true);

        try {
            rename($sourcePath, $destinationAlbumPath.DIRECTORY_SEPARATOR.'01.mp3');
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
            $scanner->scan($this->createScan($sourceRoot));
            $this->assertDatabaseHas(MediaFile::class, [
                'id' => $mediaFile->id,
                'status' => MediaFileStatus::Missing->value,
            ]);

            $destinationRoot = $sourceRoot->library->roots()->create([
                'name' => 'Destination Root',
                'path' => $destinationPath,
                'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $destinationPath))),
                'cover_image_paths' => ['cover.jpg'],
            ]);
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
            $destinationScan = $this->createScan($destinationRoot);
            $scanner->scan($destinationScan);

            $this->assertSame(0, $destinationScan->fresh()->files_added);
            $this->assertSame(1, $destinationScan->fresh()->files_updated);
            $this->assertDatabaseHas(MediaFile::class, [
                'id' => $mediaFile->id,
                'library_root_id' => $destinationRoot->id,
                'relative_path' => 'Bjoerk/Debut/01.mp3',
                'status' => MediaFileStatus::Available->value,
            ]);
            $this->assertDatabaseHas(Track::class, [
                'id' => $track->id,
                'media_file_id' => $mediaFile->id,
                'album_id' => $album->id,
            ]);
            $this->assertDatabaseHas(Album::class, [
                'id' => $album->id,
                'library_root_id' => $destinationRoot->id,
            ]);
            $this->assertDatabaseHas(PlaylistItem::class, [
                'playlist_id' => $playlist->id,
                'track_id' => $track->id,
            ]);
            $this->assertDatabaseCount(MediaFile::class, 1);
            $this->assertDatabaseCount(Track::class, 1);
            $this->assertDatabaseCount(Album::class, 1);
        } finally {
            $this->removeDirectory($destinationPath);
        }
    }

    public function test_scanner_restores_cross_root_identity_when_destination_is_scanned_first(): void
    {
        $sourcePath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($sourcePath, 'ID3:title=Old|AUDIO|same audio data');
        $sourceRoot = $this->createRoot();
        $scanner = $this->app->make(LibraryScanner::class);
        $scanner->scan($this->createScan($sourceRoot));
        $mediaFile = MediaFile::firstOrFail();
        $track = Track::firstOrFail();
        $album = Album::firstOrFail();
        $playlist = Playlist::create(['name' => 'Destination first']);
        PlaylistItem::create([
            'playlist_id' => $playlist->id,
            'track_id' => $track->id,
            'position' => 0,
        ]);

        $destinationPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sonotheque-destination-'.Str::uuid();
        $destinationAlbumPath = $destinationPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        mkdir($destinationAlbumPath, recursive: true);

        try {
            $destinationRoot = $sourceRoot->library->roots()->create([
                'name' => 'Destination Root',
                'path' => $destinationPath,
                'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $destinationPath))),
                'cover_image_paths' => ['cover.jpg'],
            ]);
            rename($sourcePath, $destinationAlbumPath.DIRECTORY_SEPARATOR.'01.mp3');
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());

            $scanner->scan($this->createScan($destinationRoot));

            $this->assertDatabaseHas(MediaFile::class, [
                'id' => $mediaFile->id,
                'library_root_id' => $destinationRoot->id,
                'relative_path' => 'Bjoerk/Debut/01.mp3',
                'status' => MediaFileStatus::Available->value,
            ]);
            $this->assertDatabaseHas(Track::class, [
                'id' => $track->id,
                'media_file_id' => $mediaFile->id,
                'album_id' => $album->id,
            ]);
            $this->assertDatabaseHas(Album::class, [
                'id' => $album->id,
                'library_root_id' => $destinationRoot->id,
            ]);
            $this->assertDatabaseHas(PlaylistItem::class, [
                'playlist_id' => $playlist->id,
                'track_id' => $track->id,
            ]);
            $this->assertDatabaseCount(MediaFile::class, 1);
            $this->assertDatabaseCount(Track::class, 1);
            $this->assertDatabaseCount(Album::class, 1);
        } finally {
            $this->removeDirectory($destinationPath);
        }
    }

    public function test_scanner_does_not_reconcile_ambiguous_duplicate_audio_files(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        $oldPath = $albumPath.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($oldPath, 'ID3:title=Old|AUDIO|duplicate audio');
        $root = $this->createRoot();
        $scanner = $this->app->make(LibraryScanner::class);
        $scanner->scan($this->createScan($root));
        $oldMediaFileId = MediaFile::firstOrFail()->id;

        rename($oldPath, $albumPath.DIRECTORY_SEPARATOR.'02.mp3');
        copy(
            $albumPath.DIRECTORY_SEPARATOR.'02.mp3',
            $albumPath.DIRECTORY_SEPARATOR.'03.mp3',
        );
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $scan = $this->createScan($root);
        $scanner->scan($scan);

        $this->assertSame(2, $scan->fresh()->files_added);
        $this->assertSame(1, $scan->fresh()->files_removed);
        $this->assertDatabaseHas(MediaFile::class, [
            'id' => $oldMediaFileId,
            'status' => MediaFileStatus::Missing->value,
        ]);
        $this->assertDatabaseCount(MediaFile::class, 3);
    }

    public function test_subtree_scan_removes_only_stale_files_inside_its_scope(): void
    {
        $scopedTrack = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        $otherAlbum = $this->musicPath.DIRECTORY_SEPARATOR.'Other'.DIRECTORY_SEPARATOR.'Album';
        $otherTrack = $otherAlbum.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($scopedTrack, 'fake audio data');
        mkdir($otherAlbum, recursive: true);
        file_put_contents($otherTrack, 'fake audio data');
        $root = $this->createRoot();
        $scanner = $this->app->make(LibraryScanner::class);
        $scanner->scan($this->createScan($root));
        $fullScanFinishedAt = $root->fresh()->last_scanned_at;

        unlink($scopedTrack);
        unlink($otherTrack);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(10));
        $scan = $root->scanRuns()->create([
            'status' => ScanStatus::Pending,
            'trigger' => ScanTrigger::Manual,
            'subtree_path' => 'Bjoerk',
        ]);

        $scanner->scan($scan);

        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status);
        $this->assertSame(1, $scan->fresh()->files_removed);
        $this->assertSame('Bjoerk', $scan->fresh()->summary['subtreePath']);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Bjoerk/Debut/01.mp3',
            'status' => MediaFileStatus::Missing->value,
        ]);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Other/Album/01.mp3',
            'status' => MediaFileStatus::Available->value,
        ]);
        $this->assertTrue($fullScanFinishedAt->equalTo($root->fresh()->last_scanned_at));
    }

    public function test_delta_scan_reconciles_only_explicit_existing_and_missing_paths(): void
    {
        $changedAlbum = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        $removedAlbum = $this->musicPath.DIRECTORY_SEPARATOR.'Other'.DIRECTORY_SEPARATOR.'Removed';
        $untouchedAlbum = $this->musicPath.DIRECTORY_SEPARATOR.'Third'.DIRECTORY_SEPARATOR.'Untouched';
        mkdir($removedAlbum, recursive: true);
        mkdir($untouchedAlbum, recursive: true);
        file_put_contents($changedAlbum.DIRECTORY_SEPARATOR.'01.mp3', 'first');
        file_put_contents($removedAlbum.DIRECTORY_SEPARATOR.'01.mp3', 'second');
        file_put_contents($untouchedAlbum.DIRECTORY_SEPARATOR.'01.mp3', 'third');
        $root = $this->createRoot();
        $scanner = $this->app->make(LibraryScanner::class);
        $scanner->scan($this->createScan($root));
        $fullScanFinishedAt = $root->fresh()->last_scanned_at;

        unlink($changedAlbum.DIRECTORY_SEPARATOR.'01.mp3');
        unlink($removedAlbum.DIRECTORY_SEPARATOR.'01.mp3');
        rmdir($removedAlbum);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(10));
        $scan = $root->scanRuns()->create([
            'status' => ScanStatus::Pending,
            'trigger' => ScanTrigger::Watcher,
            'scan_paths' => ['Bjoerk/Debut'],
            'missing_paths' => ['Other/Removed'],
        ]);

        $scanner->scan($scan);

        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status);
        $this->assertSame(2, $scan->fresh()->files_removed);
        $this->assertSame(['Bjoerk/Debut'], $scan->fresh()->summary['scanPaths']);
        $this->assertSame(['Other/Removed'], $scan->fresh()->summary['missingPaths']);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Bjoerk/Debut/01.mp3',
            'status' => MediaFileStatus::Missing->value,
        ]);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Other/Removed/01.mp3',
            'status' => MediaFileStatus::Missing->value,
        ]);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Third/Untouched/01.mp3',
            'status' => MediaFileStatus::Available->value,
        ]);
        $this->assertTrue($fullScanFinishedAt->equalTo($root->fresh()->last_scanned_at));
    }

    public function test_empty_first_scan_completes_with_actionable_warnings(): void
    {
        $invalidPath = $this->musicPath.DIRECTORY_SEPARATOR.'loose-track.mp3';
        file_put_contents($invalidPath, 'fake audio data');
        $scan = $this->createScan($this->createRoot());

        $this->app->make(LibraryScanner::class)->scan($scan);

        $scan->refresh();
        $this->assertSame(ScanStatus::Completed, $scan->status);
        $this->assertSame(0, $scan->files_discovered);
        $this->assertSame(2, $scan->warning_count);
        $this->assertSame(['invalid_layout', 'no_music_files'], array_column($scan->summary['issues'], 'code'));
    }

    public function test_empty_rescan_retains_catalog_identity_as_unavailable(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $root = $this->createRoot();
        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));
        unlink($trackPath);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $scan = $this->createScan($root);

        $this->app->make(LibraryScanner::class)->scan($scan);

        $scan->refresh();
        $this->assertSame(ScanStatus::Completed, $scan->status);
        $this->assertSame(1, $scan->files_removed);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Bjoerk/Debut/01.mp3',
            'status' => MediaFileStatus::Missing->value,
        ]);
        $this->assertDatabaseCount(Track::class, 1);
        $this->assertDatabaseCount(Album::class, 1);
        $this->assertDatabaseCount(Artist::class, 1);
        $this->assertDatabaseCount(Genre::class, 1);
    }

    public function test_unavailable_root_fails_with_an_actionable_scan_issue(): void
    {
        $trackPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $root = $this->createRoot();
        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));
        $this->assertDatabaseCount(MediaFile::class, 1);
        $scan = $this->createScan($root);
        $this->removeDirectory($this->musicPath);

        try {
            $this->app->make(LibraryScanner::class)->scan($scan);
        } catch (\RuntimeException) {
            // Queue processing records the failure before allowing the job to fail.
        }

        $scan->refresh();
        $this->assertSame(ScanStatus::Failed, $scan->status);
        $this->assertSame(1, $scan->error_count);
        $this->assertSame('scan_failed', $scan->summary['issues'][0]['code']);
        $this->assertStringContainsString('does not exist or is not readable', $scan->summary['error']);
        $this->assertDatabaseCount(MediaFile::class, 1);
        $this->assertDatabaseCount(Track::class, 1);
    }

    public function test_scanner_caches_a_configured_folder_cover_on_an_unchanged_album(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'fake audio data');
        $root = $this->createRoot();

        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));
        $this->assertNull(Album::sole()->artwork_id);

        $this->createCover($albumPath.DIRECTORY_SEPARATOR.'cover.jpg', 640, 320);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $scan = $this->createScan($root);
        $this->app->make(LibraryScanner::class)->scan($scan);

        $artwork = Artwork::sole();
        $album = Album::with('artwork')->sole();

        $this->assertSame($artwork->id, $album->artwork->id);
        $this->assertSame(ArtworkSource::Folder, $artwork->source_type);
        $this->assertSame('cover.jpg', $artwork->source_relative_path);
        $this->assertSame(ArtworkSource::Folder, $album->artwork_source_type);
        $this->assertSame('cover.jpg', $album->artwork_source_relative_path);
        $this->assertSame(640, $artwork->width);
        $this->assertSame(320, $artwork->height);
        $this->assertSame(1, $this->metadataReader->calls);
        $this->assertSame(0, $scan->fresh()->files_updated);
        Storage::disk('artwork')->assertExists($artwork->thumbnail_path);

        [$thumbnailWidth, $thumbnailHeight, $thumbnailType] = getimagesize(
            Storage::disk('artwork')->path($artwork->thumbnail_path),
        );

        $this->assertSame(320, $thumbnailWidth);
        $this->assertSame(160, $thumbnailHeight);
        $this->assertSame(IMAGETYPE_WEBP, $thumbnailType);
    }

    public function test_scanner_uses_the_first_existing_configured_cover_path(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        $discPath = $albumPath.DIRECTORY_SEPARATOR.'Disc 1';
        mkdir($discPath);
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'fake audio data');
        $this->createCover($discPath.DIRECTORY_SEPARATOR.'Front.jpg', 600, 600);
        $root = $this->createRoot();
        $root->update([
            'cover_image_paths' => ['missing.jpg', 'Disc 1/Front.jpg'],
        ]);

        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));

        $artwork = Artwork::sole();
        $this->assertSame(ArtworkSource::Folder, $artwork->source_type);
        $this->assertSame('Disc 1/Front.jpg', $artwork->source_relative_path);
        $this->assertNotNull(Album::sole()->artwork_id);
    }

    public function test_scanner_uses_a_parent_relative_cover_path_within_the_library_root(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        $coverPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Cover';
        mkdir($coverPath);
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'fake audio data');
        $this->createCover($coverPath.DIRECTORY_SEPARATOR.'Front.jpg', 600, 600);
        $root = $this->createRoot();
        $root->update([
            'cover_image_paths' => ['../Cover/Front.jpg'],
        ]);

        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));

        $artwork = Artwork::sole();
        $this->assertSame(ArtworkSource::Folder, $artwork->source_type);
        $this->assertSame('../Cover/Front.jpg', $artwork->source_relative_path);
        $this->assertNotNull(Album::sole()->artwork_id);
    }

    public function test_scanner_prunes_excluded_directories_despite_an_unrelated_unreadable_directory(): void
    {
        $includedPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        $excludedPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Incoming';
        mkdir($excludedPath);
        file_put_contents($includedPath.DIRECTORY_SEPARATOR.'01.mp3', 'fake audio data');
        file_put_contents($excludedPath.DIRECTORY_SEPARATOR.'02.mp3', 'fake audio data');
        $root = $this->createRoot();
        $scanner = $this->app->make(LibraryScanner::class);
        $scanner->scan($this->createScan($root));
        $this->assertDatabaseCount(Track::class, 2);

        $root->update(['excluded_directories' => ['Bjoerk/Incoming']]);
        $this->mockUnreadableDirectory('System Volume Information');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $scan = $this->createScan($root);

        $this->app->make(LibraryScanner::class)->scan($scan);

        $this->assertSame(1, $scan->fresh()->files_discovered);
        $this->assertSame(1, $scan->fresh()->files_removed);
        $this->assertSame(2, $this->metadataReader->calls);
        $this->assertDatabaseCount(Track::class, 2);
        $this->assertDatabaseCount(Album::class, 2);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Bjoerk/Incoming/02.mp3',
            'status' => MediaFileStatus::Missing->value,
        ]);
        $issueCodes = array_column($scan->fresh()->summary['issues'], 'code');
        $this->assertContains('files_unavailable', $issueCodes);
        $this->assertContains('unreadable_directory', $issueCodes);
        $this->assertNotContains('stale_cleanup_preserved', $issueCodes);
    }

    public function test_scanner_preserves_stale_files_beneath_an_unreadable_directory(): void
    {
        $readablePath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut'.DIRECTORY_SEPARATOR.'01.mp3';
        $unreadablePath = $this->musicPath.DIRECTORY_SEPARATOR.'Unreadable'.DIRECTORY_SEPARATOR.'Artist'.DIRECTORY_SEPARATOR.'Album';
        mkdir($unreadablePath, recursive: true);
        file_put_contents($readablePath, 'fake audio data');
        file_put_contents($unreadablePath.DIRECTORY_SEPARATOR.'02.mp3', 'fake audio data');
        $root = $this->createRoot();
        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));
        $this->assertDatabaseCount(MediaFile::class, 2);

        $this->mockUnreadableDirectory('Unreadable', skipContents: true);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $scan = $this->createScan($root);
        $this->app->make(LibraryScanner::class)->scan($scan);

        $this->assertSame(0, $scan->fresh()->files_removed);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Unreadable/Artist/Album/02.mp3',
        ]);
        $this->assertContains('stale_cleanup_preserved', array_column($scan->summary['issues'], 'code'));
    }

    public function test_scanner_uses_the_track_parent_folder_as_album_when_root_has_prefix_directories(): void
    {
        $albumPath = $this->musicPath
            .DIRECTORY_SEPARATOR.'L'
            .DIRECTORY_SEPARATOR.'Lyvten'
            .DIRECTORY_SEPARATOR.'Sondern Vom Mut Mit Dem Du Lebst (2015)';
        $coverPath = $albumPath.DIRECTORY_SEPARATOR.'Cover';
        mkdir($coverPath, recursive: true);
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'fake audio data');
        $this->createCover($coverPath.DIRECTORY_SEPARATOR.'Front.jpg', 640, 640);
        $root = $this->createRoot();
        $root->update(['cover_image_paths' => ['Cover/Front.jpg']]);

        $scanner = $this->app->make(LibraryScanner::class);
        $scanner->scan($this->createScan($root));

        $album = Album::with('artwork')->sole();
        $this->assertSame('L/Lyvten/Sondern Vom Mut Mit Dem Du Lebst (2015)', $album->relative_path);
        $this->assertNotNull($album->artwork);
        $this->assertSame(ArtworkSource::Folder, $album->artwork->source_type);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $secondScan = $this->createScan($root);
        $scanner->scan($secondScan);

        $this->assertSame(1, $this->metadataReader->calls);
        $this->assertSame(0, $secondScan->fresh()->files_updated);
        $this->assertDatabaseCount(Album::class, 1);
    }

    public function test_scanner_repairs_existing_album_paths_when_folder_depth_changes(): void
    {
        $albumPath = $this->musicPath
            .DIRECTORY_SEPARATOR.'L'
            .DIRECTORY_SEPARATOR.'Lyvten'
            .DIRECTORY_SEPARATOR.'Sondern Vom Mut Mit Dem Du Lebst (2015)';
        $coverPath = $albumPath.DIRECTORY_SEPARATOR.'Cover';
        mkdir($coverPath, recursive: true);
        $trackPath = $albumPath.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($trackPath, 'fake audio data');
        $this->createCover($coverPath.DIRECTORY_SEPARATOR.'Front.jpg', 640, 640);
        $root = $this->createRoot();
        $root->update(['cover_image_paths' => ['Cover/Front.jpg']]);
        $artist = Artist::create(['name' => 'Lyvten', 'sort_name' => 'Lyvten', 'browse_initial' => 'L']);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Sondern Vom Mut Mit Dem Du Lebst',
            'sort_title' => 'Sondern Vom Mut Mit Dem Du Lebst',
            'relative_path' => 'L/Lyvten',
            'relative_path_hash' => hash('sha256', 'l/lyvten'),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => 'L/Lyvten/Sondern Vom Mut Mit Dem Du Lebst (2015)/01.mp3',
            'relative_path_hash' => hash('sha256', 'l/lyvten/sondern vom mut mit dem du lebst (2015)/01.mp3'),
            'file_size' => filesize($trackPath),
            'modified_at' => CarbonImmutable::createFromTimestamp(filemtime($trackPath)),
            'last_seen_at' => now(),
            'status' => MediaFileStatus::Available,
        ]);
        Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Weisse Pyramiden',
            'sort_title' => 'Weisse Pyramiden',
        ]);

        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));

        $album->refresh();
        $this->assertSame('L/Lyvten/Sondern Vom Mut Mit Dem Du Lebst (2015)', $album->relative_path);
        $this->assertNotNull($album->artwork_id);
        $this->assertSame($album->id, $mediaFile->fresh()->album_id);
        $this->assertDatabaseCount(Album::class, 1);
    }

    public function test_scanner_consolidates_legacy_disc_folder_albums_under_their_parent_album(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        $firstDisc = $albumPath.DIRECTORY_SEPARATOR.'Disc 1 - Elephants';
        $secondDisc = $albumPath.DIRECTORY_SEPARATOR.'Disc 2 - Teeth Sinking Into Heart';
        mkdir($firstDisc);
        mkdir($secondDisc);
        $firstTrackPath = $firstDisc.DIRECTORY_SEPARATOR.'01.mp3';
        $secondTrackPath = $secondDisc.DIRECTORY_SEPARATOR.'01.mp3';
        file_put_contents($firstTrackPath, 'first fake audio');
        file_put_contents($secondTrackPath, 'second fake audio');
        $root = $this->createRoot();
        $artist = Artist::create(['name' => 'BjÃ¶rk', 'sort_name' => 'BjÃ¶rk', 'browse_initial' => 'B']);

        foreach ([
            ['Disc 1 - Elephants', $firstTrackPath, 'first fake audio'],
            ['Disc 2 - Teeth Sinking Into Heart', $secondTrackPath, 'second fake audio'],
        ] as $index => [$discFolder, $trackPath, $contents]) {
            $albumRelativePath = 'Bjoerk/Debut/'.$discFolder;
            $trackRelativePath = $albumRelativePath.'/01.mp3';
            $album = Album::create([
                'library_root_id' => $root->id,
                'primary_artist_id' => $artist->id,
                'title' => 'Debut',
                'sort_title' => 'Debut',
                'relative_path' => $albumRelativePath,
                'relative_path_hash' => hash('sha256', strtolower($albumRelativePath)),
            ]);
            $mediaFile = MediaFile::create([
                'library_root_id' => $root->id,
                'album_id' => $album->id,
                'relative_path' => $trackRelativePath,
                'relative_path_hash' => hash('sha256', strtolower($trackRelativePath)),
                'file_size' => strlen($contents),
                'modified_at' => CarbonImmutable::createFromTimestamp(filemtime($trackPath)),
                'last_seen_at' => now(),
                'status' => MediaFileStatus::Available,
            ]);
            Track::create([
                'album_id' => $album->id,
                'media_file_id' => $mediaFile->id,
                'title' => 'Track '.($index + 1),
                'sort_title' => 'Track '.($index + 1),
                'disc_number' => $index + 1,
                'track_number' => 1,
            ]);
        }

        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));

        $album = Album::withCount(['mediaFiles', 'tracks'])->sole();
        $this->assertSame('Bjoerk/Debut', $album->relative_path);
        $this->assertSame(2, $album->media_files_count);
        $this->assertSame(2, $album->tracks_count);
        $this->assertSame([$album->id], MediaFile::query()->distinct()->pluck('album_id')->all());
        $this->assertSame([$album->id], Track::query()->distinct()->pluck('album_id')->all());
        $this->assertSame(2, $this->metadataReader->calls);
    }

    public function test_successful_scan_deletes_albums_without_media_files(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'fake audio data');
        $root = $this->createRoot();
        $artist = Artist::create(['name' => 'Bjoerk', 'sort_name' => 'Bjoerk', 'browse_initial' => 'B']);
        $orphanedAlbum = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Bjoerk',
            'sort_title' => 'Bjoerk',
            'relative_path' => 'Bjoerk',
            'relative_path_hash' => hash('sha256', 'bjoerk'),
        ]);

        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));

        $this->assertDatabaseMissing(Album::class, ['id' => $orphanedAlbum->id]);
        $this->assertDatabaseHas(Album::class, ['title' => 'Debut']);
    }

    public function test_invalid_folder_cover_is_a_nonfatal_scan_warning(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'fake audio data');
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'cover.jpg', 'not an image');
        $root = $this->createRoot();
        $scan = $this->createScan($root);

        $this->app->make(LibraryScanner::class)->scan($scan);

        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status);
        $this->assertSame(2, $scan->fresh()->warning_count);
        $this->assertSame(0, $scan->fresh()->error_count);
        $this->assertDatabaseCount(Artwork::class, 0);
    }

    public function test_scanner_uses_embedded_artwork_when_the_folder_cover_is_missing(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'fake audio data');
        $this->metadataReader = new FakeAudioMetadataReader(new AudioMetadata(
            title: 'Human Behaviour',
            album: 'Debut',
            albumArtist: 'Björk',
            artists: ['Björk'],
            embeddedArtwork: new EmbeddedArtwork($this->createCoverBytes(500, 500), 'image/jpeg'),
        ));
        $this->app->instance(AudioMetadataReader::class, $this->metadataReader);

        $this->app->make(LibraryScanner::class)->scan($this->createScan($this->createRoot()));

        $artwork = Artwork::sole();

        $this->assertSame(ArtworkSource::Embedded, $artwork->source_type);
        $this->assertNull($artwork->source_relative_path);
        $album = Album::sole();
        $this->assertSame($artwork->id, $album->artwork_id);
        $this->assertSame(ArtworkSource::Embedded, $album->artwork_source_type);
        $this->assertNull($album->artwork_source_relative_path);
        Storage::disk('artwork')->assertExists($artwork->thumbnail_path);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $this->app->make(LibraryScanner::class)->scan($this->createScan(LibraryRoot::sole()));

        $this->assertSame(1, $this->metadataReader->calls);
        $this->assertDatabaseCount(Artwork::class, 1);
    }

    public function test_malformed_track_does_not_replace_valid_album_metadata(): void
    {
        $albumPath = $this->musicPath.DIRECTORY_SEPARATOR.'Bjoerk'.DIRECTORY_SEPARATOR.'Debut';
        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'01.mp3', 'valid fake audio');
        $root = $this->createRoot();

        $this->app->make(LibraryScanner::class)->scan($this->createScan($root));

        file_put_contents($albumPath.DIRECTORY_SEPARATOR.'02-broken.mp3', 'broken fake audio');
        $this->metadataReader->failOn('02-broken.mp3');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $scan = $this->createScan($root);
        $this->app->make(LibraryScanner::class)->scan($scan);

        $album = Album::with('primaryArtist')->sole();

        $this->assertSame('Björk', $album->primaryArtist->name);
        $this->assertSame(1993, $album->original_release_year);
        $this->assertSame(1, $scan->fresh()->error_count);
        $this->assertSame('file_error', $scan->fresh()->summary['issues'][0]['code']);
        $this->assertSame('Bjoerk/Debut/02-broken.mp3', $scan->fresh()->summary['issues'][0]['path']);
        $this->assertDatabaseHas(MediaFile::class, [
            'relative_path' => 'Bjoerk/Debut/02-broken.mp3',
            'status' => MediaFileStatus::Error->value,
        ]);
    }

    public function test_scan_command_dispatches_a_database_scan_job(): void
    {
        Queue::fake();
        $root = $this->createRoot();

        $this->artisan('music:scan', ['root' => $root->id])
            ->expectsOutputToContain('queued.')
            ->assertSuccessful();

        $scanRun = ScanRun::sole();

        Queue::assertPushed(
            ScanLibraryRoot::class,
            fn (ScanLibraryRoot $job): bool => $job->scanRunId === $scanRun->id
                && $job->queue === 'scans',
        );
        $this->assertDatabaseHas(ScanRun::class, [
            'library_root_id' => $root->id,
            'status' => ScanStatus::Pending->value,
            'trigger' => ScanTrigger::Manual->value,
        ]);
    }

    public function test_cancelled_pending_scan_is_not_started_by_its_queued_job(): void
    {
        $root = $this->createRoot();
        $scan = $this->createScan($root);
        $scan->update([
            'status' => ScanStatus::Cancelled,
            'cancel_requested_at' => now(),
            'finished_at' => now(),
            'summary' => ['phase' => 'cancelled'],
        ]);

        $this->app->make(LibraryScanner::class)->scan($scan);

        $this->assertSame(ScanStatus::Cancelled, $scan->fresh()->status);
        $this->assertNull($scan->fresh()->started_at);
        $this->assertNull($root->fresh()->last_scanned_at);
        $this->assertSame(0, $this->metadataReader->calls);
    }

    private function createRoot(): LibraryRoot
    {
        $library = Library::create(['name' => 'Test Library']);

        return $library->roots()->create([
            'name' => 'Test Root',
            'path' => $this->musicPath,
            'path_hash' => hash('sha256', mb_strtolower(str_replace('\\', '/', $this->musicPath))),
            'cover_image_paths' => ['cover.jpg'],
        ]);
    }

    private function mockUnreadableDirectory(string $relativePath, bool $skipContents = false): void
    {
        $realDiscoverer = $this->app->make(AudioFileDiscoverer::class);
        $discoverer = \Mockery::mock(AudioFileDiscoverer::class);
        $discoverer->shouldReceive('discover')
            ->once()
            ->andReturnUsing(function (LibraryRoot $libraryRoot, ?DiscoveryDiagnostics $diagnostics) use ($realDiscoverer, $relativePath, $skipContents): \Generator {
                $diagnostics?->record(
                    'unreadable_directory',
                    'A directory could not be read and was skipped.',
                    $relativePath,
                );

                foreach ($realDiscoverer->discover($libraryRoot, $diagnostics) as $file) {
                    if (! $skipContents || ! str_starts_with($file->relativePath, $relativePath.'/')) {
                        yield $file;
                    }
                }
            });
        $this->app->instance(AudioFileDiscoverer::class, $discoverer);
    }

    private function createScan(LibraryRoot $root): ScanRun
    {
        return $root->scanRuns()->create([
            'status' => ScanStatus::Pending,
            'trigger' => ScanTrigger::Manual,
        ]);
    }

    private function createCover(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 35, 75, 120);
        imagefill($image, 0, 0, $background);
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }

    private function createCoverBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 120, 60, 35);
        imagefill($image, 0, 0, $background);
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return is_string($bytes) ? $bytes : '';
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
