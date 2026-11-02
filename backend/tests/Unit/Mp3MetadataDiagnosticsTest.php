<?php

namespace Tests\Unit;

use App\Music\Scanning\GetId3MetadataReader;
use App\Music\Scanning\Mp3MetadataDiagnostics;
use App\Music\Scanning\RawMetadataSanitizer;
use PHPUnit\Framework\TestCase;

class Mp3MetadataDiagnosticsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'secondary-id3-');
        $this->path = $temporary.'.mp3';
        rename($temporary, $this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_it_ignores_complete_secondary_tags_without_changing_the_file_or_primary_values(): void
    {
        $primary = $this->tag('Current title', 3);
        $secondary = $this->tag('Old title', 4, 0x80).$this->tag('Older title', 3);
        $audio = str_repeat("\xff\xfb\x90\x64".str_repeat("\0", 413), 64);
        file_put_contents($this->path, $primary.$secondary.$audio);
        $before = hash_file('sha256', $this->path);

        $metadata = (new GetId3MetadataReader(new RawMetadataSanitizer()))->read($this->path);

        $this->assertSame('Current title', $metadata->title);
        $this->assertSame([], $metadata->warnings);
        $this->assertSame([[
            'offset' => strlen($primary), 'length' => strlen($secondary), 'tagCount' => 2,
        ]], $metadata->rawMetadata['sonotheque_diagnostics']['ignored_secondary_id3_tags']);
        $this->assertStringContainsString('Unknown data before synch', implode(' ', $metadata->rawMetadata['warning']));
        $this->assertSame($before, hash_file('sha256', $this->path));
    }

    public function test_only_the_byte_discrepancy_fully_explained_by_secondary_tags_is_ignored(): void
    {
        $tag = $this->tag('Old title', 4);
        file_put_contents($this->path, str_repeat('x', 20).$tag.str_repeat('a', 100));
        $info = ['fileformat' => 'mp3', 'id3v2' => ['tag_offset_end' => 20],
            'avdataoffset' => 20 + strlen($tag), 'avdataend' => 120 + strlen($tag)];
        $classifier = new Mp3MetadataDiagnostics();
        $ranges = $classifier->secondaryTags($this->path, $info);
        $length = strlen($tag);
        $found = 100 + $length;

        $this->assertTrue($classifier->explainedBySecondaryTags(
            "Too much data in file: expecting 100 bytes of audio data, found {$found} ({$length} bytes too many)",
            $info,
            $ranges,
        ));
        $this->assertFalse($classifier->explainedBySecondaryTags(
            'Too much data in file: expecting 100 bytes of audio data, found 228 (128 bytes too many)',
            $info,
            $ranges,
        ));
        $this->assertFalse($classifier->explainedBySecondaryTags('Invalid ID3v2 frame size, aborting.', $info, $ranges));
        $info['avdataend']--;
        $this->assertFalse($classifier->explainedBySecondaryTags(
            "Too much data in file: expecting 100 bytes of audio data, found {$found} ({$length} bytes too many)",
            $info,
            $ranges,
        ));
    }

    public function test_unknown_incomplete_and_malformed_regions_are_not_ignored(): void
    {
        $tag = $this->tag('Old title', 4);
        foreach (['random bytes', substr($tag, 0, -1), $tag.'unknown bytes',
            substr_replace($tag, "\xff", 6, 1), substr_replace($tag, '????', 10, 4),
            substr_replace($tag, "\x40", 5, 1), substr_replace($tag, "\0\0\x7f\x7f", 14, 4),
        ] as $region) {
            file_put_contents($this->path, str_repeat('x', 20).$region.'audio');
            $this->assertSame([], (new Mp3MetadataDiagnostics())->secondaryTags($this->path, [
                'fileformat' => 'mp3', 'id3v2' => ['tag_offset_end' => 20], 'avdataoffset' => 20 + strlen($region),
            ]));
        }
    }

    private function tag(string $title, int $version, int $flags = 0): string
    {
        $body = "\0".$title;
        $payload = 'TIT2'.pack('N', strlen($body))."\0\0".$body.str_repeat("\0", 8);
        $size = strlen($payload);

        return 'ID3'.chr($version)."\0".chr($flags)
            .pack('C4', ($size >> 21) & 127, ($size >> 14) & 127, ($size >> 7) & 127, $size & 127).$payload;
    }
}
