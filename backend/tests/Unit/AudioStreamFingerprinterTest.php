<?php

namespace Tests\Unit;

use App\Music\Metadata\Mp3Id3v2TagEditor;
use App\Music\Scanning\AudioStreamFingerprinter;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class AudioStreamFingerprinterTest extends TestCase
{
    public function test_sonotheque_id3_edits_do_not_change_the_audio_fingerprint(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sonotheque-fingerprint-').'.mp3';
        $titleFrame = 'TIT2'.pack('N', 10)."\0\0\0Old title";
        $tagPayload = $titleFrame.str_repeat("\0", 1024 - strlen($titleFrame));
        file_put_contents(
            $path,
            'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($tagPayload)).$tagPayload
                .str_repeat("\xFF\xFB\x90\x64", 128),
        );
        $fingerprinter = new AudioStreamFingerprinter('ffmpeg', 30);

        try {
            $before = $fingerprinter->fingerprint($path);
            (new Mp3Id3v2TagEditor())->write(
                $path,
                ['TIT2' => 'A longer replacement title'],
                [],
                static function (): void {
                },
            );

            $this->assertSame($before, $fingerprinter->fingerprint($path));
        } finally {
            unlink($path);
        }
    }

    public function test_it_hashes_only_the_audio_byte_range(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sonotheque-fingerprint-');
        file_put_contents($path, 'ID3 metadata|AUDIO|audio payload|TAG metadata');
        $fingerprinter = new class ('ffmpeg', 30) extends AudioStreamFingerprinter {
            protected function audioByteRange(string $absolutePath): ?array
            {
                return ['offset' => 19, 'length' => 13];
            }
        };

        try {
            $this->assertSame(hash('sha256', 'audio payload'), $fingerprinter->fingerprint($path));
        } finally {
            unlink($path);
        }
    }

    public function test_it_uses_an_encoded_audio_stream_hash_when_no_byte_range_is_available(): void
    {
        $fingerprint = str_repeat('a', 64);
        Process::fake([
            '*' => Process::result(output: "SHA256={$fingerprint}\n"),
        ]);
        $fingerprinter = new class ('ffmpeg', 30) extends AudioStreamFingerprinter {
            protected function audioByteRange(string $absolutePath): ?array
            {
                return null;
            }
        };

        $result = $fingerprinter->fingerprint('C:/Music/Artist/Album/track.mp3');

        $this->assertSame($fingerprint, $result);
        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;

            return is_array($command)
                && in_array('-map_metadata', $command, true)
                && in_array('-map_chapters', $command, true)
                && in_array('-c:a', $command, true)
                && in_array('copy', $command, true)
                && in_array('0:a:0', $command, true)
                && in_array('sha256', $command, true);
        });
    }

    public function test_it_rejects_invalid_fallback_output(): void
    {
        Process::fake(['*' => Process::result(output: 'not a fingerprint')]);
        $fingerprinter = new class ('ffmpeg', 30) extends AudioStreamFingerprinter {
            protected function audioByteRange(string $absolutePath): ?array
            {
                return null;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FFmpeg returned an invalid audio fingerprint.');

        $fingerprinter->fingerprint('track.mp3');
    }

    private function synchsafe(int $value): string
    {
        return pack(
            'C4',
            ($value >> 21) & 0x7F,
            ($value >> 14) & 0x7F,
            ($value >> 7) & 0x7F,
            $value & 0x7F,
        );
    }
}
