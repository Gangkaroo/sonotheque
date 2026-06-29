<?php

namespace Tests\Unit;

use App\Music\PlaybackStatistics\Mp3PlaybackStatisticsTagWriter;
use App\Music\PlaybackStatistics\PlaybackStatisticsTagReader;
use App\Music\PlaybackStatistics\UnsupportedPlaybackStatisticsTagFormat;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class Mp3PlaybackStatisticsTagWriterTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'music-library-tag-writer-'.bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_it_replaces_statistics_fields_and_preserves_unrelated_frames_and_audio(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'track.mp3';
        $titleFrame = $this->frame('TIT2', "\0Unchanged title");
        $privateFrame = $this->frame('PRIV', "owner@example.test\0binary\xFFdata");
        $replayGainFrame = $this->textFrame('REPLAYGAIN_TRACK_GAIN', '-5.00 dB');
        $audio = str_repeat("\xFF\xFB\x90\x64", 128);
        $payload = $titleFrame
            .$privateFrame
            .$replayGainFrame
            .$this->textFrame('PLAY_COUNT', '2')
            .$this->textFrame('FIRST_PLAYED_TIMESTAMP', '132174539906814579')
            .$this->textFrame('LAST_PLAYED_TIMESTAMP', '132174546506372687');
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        file_put_contents($path, 'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.$audio);
        $originalSize = filesize($path);

        (new Mp3PlaybackStatisticsTagWriter(new PlaybackStatisticsTagReader))->write(
            $path,
            17,
            CarbonImmutable::parse('2020-01-02T03:04:05.123456Z'),
            CarbonImmutable::parse('2026-06-29T09:10:11.654321Z'),
        );

        $writtenFile = file_get_contents($path);
        $this->assertSame($originalSize, filesize($path));
        $this->assertStringContainsString($titleFrame, $writtenFile);
        $this->assertStringContainsString($privateFrame, $writtenFile);
        $this->assertStringContainsString($replayGainFrame, $writtenFile);
        $this->assertStringEndsWith($audio, $writtenFile);

        $information = (new \getID3)->analyze($path);
        \getid3_lib::CopyTagsToComments($information);
        $statistics = (new PlaybackStatisticsTagReader)->read($information);
        $this->assertSame(17, $statistics->playCount);
        $this->assertSame('2020-01-02T03:04:05.123456Z', $statistics->firstPlayedAt?->toJSON());
        $this->assertSame('2026-06-29T09:10:11.654321Z', $statistics->lastPlayedAt?->toJSON());
    }

    public function test_it_rejects_unsupported_files_without_changing_them(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'track.m4a';
        file_put_contents($path, 'unchanged');

        $writer = new Mp3PlaybackStatisticsTagWriter(new PlaybackStatisticsTagReader);

        try {
            $writer->write($path, 1, null, null);
            $this->fail('Expected unsupported-format exception.');
        } catch (UnsupportedPlaybackStatisticsTagFormat) {
            $this->assertSame('unchanged', file_get_contents($path));
        }
    }

    private function textFrame(string $description, string $value): string
    {
        return $this->frame('TXXX', "\0{$description}\0{$value}");
    }

    private function frame(string $name, string $payload): string
    {
        return $name.pack('N', strlen($payload))."\0\0".$payload;
    }

    private function synchsafe(int $value): string
    {
        return pack('C4',
            ($value >> 21) & 0x7F,
            ($value >> 14) & 0x7F,
            ($value >> 7) & 0x7F,
            $value & 0x7F,
        );
    }
}
