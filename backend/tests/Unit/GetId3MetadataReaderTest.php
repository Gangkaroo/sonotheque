<?php

namespace Tests\Unit;

use App\Music\Scanning\AudioMetadataProbe;
use App\Music\Scanning\GetId3MetadataReader;
use App\Music\Scanning\ProbedAudioMetadata;
use App\Music\Scanning\RawMetadataSanitizer;
use PHPUnit\Framework\TestCase;

class GetId3MetadataReaderTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'get-id3-reader-'.bin2hex(random_bytes(6));
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

    public function test_id3v2_values_take_precedence_over_legacy_id3v1_values(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'track.mp3';
        $payload = $this->frame('TIT2', "\0ID3v2 title")
            .$this->frame('TPE1', "\0ID3v2 artist")
            .$this->frame('TCON', "\0Death Metal");
        $payload .= str_repeat("\0", 1024 - strlen($payload));
        file_put_contents(
            $path,
            'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload
                .$this->audio().$this->id3v1('Legacy title', 'Legacy artist', 'Other'),
        );

        $metadata = $this->reader()->read($path);

        $this->assertSame('ID3v2 title', $metadata->title);
        $this->assertSame(['ID3v2 artist'], $metadata->artists);
        $this->assertSame(['Death Metal'], $metadata->genres);
        $this->assertContains('Other', $metadata->rawMetadata['comments']['genre']);
    }

    public function test_id3v1_values_remain_available_when_id3v2_is_absent(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'legacy.mp3';
        file_put_contents($path, $this->audio().$this->id3v1('Legacy title', 'Legacy artist', 'Other'));

        $metadata = $this->reader()->read($path);

        $this->assertSame('Legacy title', $metadata->title);
        $this->assertSame(['Legacy artist'], $metadata->artists);
        $this->assertSame(['Other'], $metadata->genres);
    }

    public function test_it_preserves_literal_slashes_in_id3v23_genres(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'slash-genre.mp3';
        $payload = $this->frame('TIT2', "\0Track title")
            .$this->frame('TCON', "\0Singer/Songwriter");
        $payload .= str_repeat("\0", 1024 - strlen($payload));
        file_put_contents(
            $path,
            'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.$this->audio(),
        );

        $metadata = $this->reader()->read($path);

        $this->assertSame(['Singer/Songwriter'], $metadata->genres);
    }

    public function test_it_ignores_an_oversized_disc_total_without_rejecting_the_track(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'malformed-disc-total.mp3';
        $payload = $this->frame('TIT2', "\0Track title")
            .$this->frame('TRCK', "\0".'01/18')
            .$this->frame('TPOS', "\0".'1/517082');
        $payload .= str_repeat("\0", 1024 - strlen($payload));
        file_put_contents(
            $path,
            'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.$this->audio(),
        );

        $metadata = $this->reader()->read($path);

        $this->assertSame(1, $metadata->trackNumber);
        $this->assertSame(1, $metadata->discNumber);
        $this->assertNull($metadata->discTotal);
        $this->assertContains(
            'The total discs tag value [517082] is outside the supported range and was ignored.',
            $metadata->warnings,
        );
    }

    public function test_it_uses_a_probe_after_get_id3_errors_without_discarding_valid_tags(): void
    {
        $probe = new class () implements AudioMetadataProbe {
            public int $calls = 0;

            public function probe(string $absolutePath): ProbedAudioMetadata
            {
                $this->calls++;

                return new ProbedAudioMetadata(
                    tags: [
                        'album' => 'Fallback album',
                        'artist' => 'Fallback artist',
                        'track' => '03',
                        'date' => '2007',
                    ],
                    durationMs: 123456,
                    container: 'mp3',
                    codec: 'mp3',
                    bitrate: 320000,
                    sampleRate: 44100,
                    channels: 2,
                    rawMetadata: ['format' => ['format_name' => 'mp3']],
                );
            }
        };
        $reader = new class (new RawMetadataSanitizer(), $probe) extends GetId3MetadataReader {
            protected function analyze(string $absolutePath): array
            {
                return [
                    'error' => ['Malformed optional tag.'],
                    'warning' => array_map(
                        static fn (int $number): string => "Parser warning {$number}.",
                        range(1, 12),
                    ),
                    'comments' => ['title' => ['Primary title']],
                ];
            }
        };

        $metadata = $reader->read('fallback.mp3');

        $this->assertSame(1, $probe->calls);
        $this->assertSame('Primary title', $metadata->title);
        $this->assertSame('Fallback album', $metadata->album);
        $this->assertSame(['Fallback artist'], $metadata->artists);
        $this->assertSame(3, $metadata->trackNumber);
        $this->assertSame(2007, $metadata->year);
        $this->assertSame(123456, $metadata->durationMs);
        $this->assertSame('audio/mpeg', $metadata->mimeType);
        $this->assertSame('mp3', $metadata->codec);
        $this->assertCount(4, $metadata->warnings);
        $this->assertSame(
            '10 additional getID3 warnings were omitted after FFprobe validated the audio stream.',
            $metadata->warnings[3],
        );
        $this->assertSame('mp3', $metadata->rawMetadata['ffprobe_fallback']['format']['format_name']);
    }

    private function reader(): GetId3MetadataReader
    {
        return new GetId3MetadataReader(new RawMetadataSanitizer());
    }

    private function frame(string $name, string $payload): string
    {
        return $name.pack('N', strlen($payload))."\0\0".$payload;
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

    private function audio(): string
    {
        return str_repeat("\xFF\xFB\x90\x64", 64);
    }

    private function id3v1(string $title, string $artist, string $genre): string
    {
        $genres = ['Blues' => 0, 'Other' => 12];

        return 'TAG'
            .str_pad($title, 30, "\0")
            .str_pad($artist, 30, "\0")
            .str_pad('Album', 30, "\0")
            .'1991'
            .str_pad('', 28, "\0")
            ."\0".chr(1).chr($genres[$genre]);
    }
}
