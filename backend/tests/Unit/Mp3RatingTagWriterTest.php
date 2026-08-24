<?php

namespace Tests\Unit;

use App\Music\Metadata\Mp3Id3v2TagEditor;
use App\Music\Ratings\Mp3RatingTagWriter;
use App\Music\Ratings\RatingTagReader;
use PHPUnit\Framework\TestCase;

class Mp3RatingTagWriterTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sonotheque-rating-writer-'.bin2hex(random_bytes(6));
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

    public function test_it_round_trips_half_star_ratings_and_preserves_other_popularimeters(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'track.mp3';
        $otherPopularimeter = $this->frame('POPM', "other@example.test\0".chr(196).pack('N', 12));
        $payload = $this->frame('TIT2', "\0Title").$otherPopularimeter;
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        $audio = str_repeat("\xFF\xFB\x90\x64", 128);
        file_put_contents($path, 'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.$audio);

        $reader = new RatingTagReader();
        $writer = new Mp3RatingTagWriter(new Mp3Id3v2TagEditor(), $reader);
        $writer->write($path, 9, 7);

        $information = (new \getID3())->analyze($path);
        \getid3_lib::CopyTagsToComments($information);
        $ratings = $reader->read($information);
        $this->assertSame(9, $ratings->trackHalfSteps);
        $this->assertSame(7, $ratings->albumHalfSteps);
        $this->assertTrue($ratings->trackTagPresent);
        $this->assertTrue($ratings->albumTagPresent);
        $this->assertStringContainsString($otherPopularimeter, file_get_contents($path));
        $this->assertStringEndsWith($audio, file_get_contents($path));

        $writer->write($path, null, null);
        $information = (new \getID3())->analyze($path);
        \getid3_lib::CopyTagsToComments($information);
        $cleared = $reader->read($information);
        $this->assertNull($cleared->trackHalfSteps);
        $this->assertNull($cleared->albumHalfSteps);
        $this->assertTrue($cleared->trackTagPresent);
        $this->assertTrue($cleared->albumTagPresent);
        $this->assertStringContainsString($otherPopularimeter, file_get_contents($path));
    }

    public function test_it_imports_common_popm_and_custom_rating_values(): void
    {
        $reader = new RatingTagReader();

        $popularimeter = $reader->read([
            'id3v2' => ['POPM' => [[
                'email' => 'Windows Media Player 9 Series',
                'rating' => 196,
            ]]],
        ]);
        $this->assertSame(8, $popularimeter->trackHalfSteps);

        $custom = $reader->read([
            'id3v2' => ['TXXX' => [[
                'description' => 'RATING',
                'data' => "RATING\0".'4.5',
            ]]],
        ]);
        $this->assertSame(9, $custom->trackHalfSteps);
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
}
