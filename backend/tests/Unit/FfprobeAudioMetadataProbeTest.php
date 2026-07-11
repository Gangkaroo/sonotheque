<?php

namespace Tests\Unit;

use App\Music\Scanning\FfprobeAudioMetadataProbe;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class FfprobeAudioMetadataProbeTest extends TestCase
{
    public function test_it_reads_the_first_audio_stream_and_format_tags(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'streams' => [
                    ['codec_type' => 'video', 'codec_name' => 'mjpeg'],
                    [
                        'codec_type' => 'audio',
                        'codec_name' => 'mp3',
                        'sample_rate' => '44100',
                        'channels' => 2,
                        'bit_rate' => '320000',
                        'duration' => '42.125',
                    ],
                ],
                'format' => [
                    'format_name' => 'mp3',
                    'tags' => ['TITLE' => 'Example', 'ALBUM_ARTIST' => 'Artist'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $metadata = (new FfprobeAudioMetadataProbe('ffprobe', 15))->probe('C:/Music/example.mp3');

        $this->assertSame(['title' => 'Example', 'album_artist' => 'Artist'], $metadata->tags);
        $this->assertSame(42125, $metadata->durationMs);
        $this->assertSame('mp3', $metadata->container);
        $this->assertSame('mp3', $metadata->codec);
        $this->assertSame(320000, $metadata->bitrate);
        $this->assertSame(44100, $metadata->sampleRate);
        $this->assertSame(2, $metadata->channels);
        Process::assertRanTimes(fn (): bool => true, 1);
    }

    public function test_it_rejects_probe_output_without_an_audio_stream(): void
    {
        Process::fake([
            '*' => Process::result(output: '{"streams":[],"format":{"format_name":"mp3"}}'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FFprobe did not find an audio stream.');

        (new FfprobeAudioMetadataProbe('ffprobe', 15))->probe('C:/Music/not-audio.mp3');
    }
}
