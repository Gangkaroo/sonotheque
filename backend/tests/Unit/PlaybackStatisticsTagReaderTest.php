<?php

namespace Tests\Unit;

use App\Music\PlaybackStatistics\PlaybackStatisticsTagReader;
use PHPUnit\Framework\TestCase;

class PlaybackStatisticsTagReaderTest extends TestCase
{
    public function test_it_reads_foobar_playback_statistics_from_nested_tag_fields(): void
    {
        $statistics = (new PlaybackStatisticsTagReader)->read([
            'comments' => [
                'text' => [
                    'PLAY_COUNT' => '42',
                    'FIRST_PLAYED_TIMESTAMP' => '2020-01-02 03:04:05',
                    'LAST_PLAYED_TIMESTAMP' => '2026-06-28T12:34:56+02:00',
                ],
            ],
        ]);

        $this->assertSame(42, $statistics->playCount);
        $this->assertSame('2020-01-02T03:04:05.000000Z', $statistics->firstPlayedAt?->toJSON());
        $this->assertSame('2026-06-28T10:34:56.000000Z', $statistics->lastPlayedAt?->toJSON());
        $this->assertSame([], $statistics->warnings);
    }

    public function test_it_reads_standard_play_counter_and_reports_invalid_dates(): void
    {
        $statistics = (new PlaybackStatisticsTagReader)->read([
            'id3v2' => [
                'comments' => [
                    'play_counter' => [7],
                    'first_played' => ['not-a-date'],
                ],
            ],
        ]);

        $this->assertSame(7, $statistics->playCount);
        $this->assertNull($statistics->firstPlayedAt);
        $this->assertCount(1, $statistics->warnings);
    }

    public function test_it_reads_foobar_windows_filetime_timestamps(): void
    {
        $statistics = (new PlaybackStatisticsTagReader)->read([
            'comments' => [
                'play_count' => ['1'],
                'first_played_timestamp' => ['132174539906814579'],
                'last_played_timestamp' => ['132174546506372687'],
            ],
        ]);

        $this->assertSame(1, $statistics->playCount);
        $this->assertSame('2019-11-05T18:59:50.681457Z', $statistics->firstPlayedAt?->toJSON());
        $this->assertSame('2019-11-05T19:10:50.637268Z', $statistics->lastPlayedAt?->toJSON());
        $this->assertSame([], $statistics->warnings);
    }
}
