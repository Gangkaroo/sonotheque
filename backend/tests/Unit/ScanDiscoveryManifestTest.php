<?php

namespace Tests\Unit;

use App\Music\Scanning\DiscoveredAudioFile;
use App\Music\Scanning\ScanDiscoveryManifest;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScanDiscoveryManifestTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'sonotheque-manifest-'
            .Str::uuid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_it_round_trips_discovered_files_and_removes_the_manifest(): void
    {
        $manifest = new ScanDiscoveryManifest($this->directory);
        $file = new DiscoveredAudioFile(
            absolutePath: 'G:/Music/Bjork/Debut/01 - Human Behaviour.mp3',
            relativePath: 'Bjork/Debut/01 - Human Behaviour.mp3',
            albumRelativePath: 'Bjork/Debut',
            artistFolder: 'Bjork',
            albumFolder: 'Debut',
            fileSize: 123456,
            modifiedAt: 1720000000,
        );

        $manifest->start(42);
        $manifest->append($file);
        $manifest->append(new DiscoveredAudioFile(
            absolutePath: "G:/Music/Rodríguez-López/Omar's \"Album\"/02 - Line\nBreak.mp3",
            relativePath: "Rodríguez-López/Omar's \"Album\"/02 - Line\nBreak.mp3",
            albumRelativePath: "Rodríguez-López/Omar's \"Album\"",
            artistFolder: 'Rodríguez-López',
            albumFolder: "Omar's \"Album\"",
            fileSize: 987654,
            modifiedAt: 1720000001,
        ));

        $restored = iterator_to_array($manifest->files(42));

        $this->assertCount(2, $restored);
        $this->assertEquals($file, $restored[0]);
        $this->assertSame(
            "Rodríguez-López/Omar's \"Album\"/02 - Line\nBreak.mp3",
            $restored[1]->relativePath,
        );
        $this->assertFileExists($this->directory.DIRECTORY_SEPARATOR.'scan-42.jsonl');

        $manifest->delete(42);

        $this->assertFileDoesNotExist($this->directory.DIRECTORY_SEPARATOR.'scan-42.jsonl');
    }

    public function test_it_rejects_a_truncated_manifest_record(): void
    {
        $manifest = new ScanDiscoveryManifest($this->directory);
        $manifest->start(43);
        $manifest->append(new DiscoveredAudioFile(
            absolutePath: 'G:/Music/Bjork/Debut/01.mp3',
            relativePath: 'Bjork/Debut/01.mp3',
            albumRelativePath: 'Bjork/Debut',
            artistFolder: 'Bjork',
            albumFolder: 'Debut',
            fileSize: 123456,
            modifiedAt: 1720000000,
        ));
        $manifest->finish();
        file_put_contents(
            $this->directory.DIRECTORY_SEPARATOR.'scan-43.jsonl',
            '["truncated"',
            FILE_APPEND,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid at line 2');

        iterator_to_array($manifest->files(43));
    }
}
