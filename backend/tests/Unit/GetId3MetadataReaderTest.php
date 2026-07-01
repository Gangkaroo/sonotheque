<?php

namespace Tests\Unit;

use App\Music\Scanning\GetId3MetadataReader;
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

    private function reader(): GetId3MetadataReader
    {
        return new GetId3MetadataReader(new RawMetadataSanitizer);
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
