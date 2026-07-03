<?php

namespace Tests\Unit;

use App\Music\Metadata\Mp3Id3v2TagEditor;
use App\Music\Metadata\Mp3TrackMetadataWriter;
use App\Music\Scanning\GetId3MetadataReader;
use App\Music\Scanning\RawMetadataSanitizer;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\ExtensionSensitiveId3MetadataReader;
use Tests\Fakes\TestId3MetadataReader;

class Mp3TrackMetadataWriterTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'music-library-track-writer-'.bin2hex(random_bytes(6));
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

    public function test_it_writes_track_fields_and_preserves_totals_unrelated_frames_and_audio(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'track.mp3';
        $privateFrame = $this->frame('PRIV', "owner@example.test\0binary-data");
        $statisticsFrame = $this->textFrame('PLAY_COUNT', '12');
        $describedComment = $this->frame('COMM', "\0engSOURCE\0Keep this comment");
        $audio = str_repeat("\xFF\xFB\x90\x64", 128);
        $payload = $this->frame('TIT2', "\0Old title")
            .$this->frame('TRCK', "\0".'2/12')
            .$this->frame('TPOS', "\0".'1/2')
            .$this->frame('TYER', "\0".'2000')
            .$this->frame('TPE1', "\0Old artist")
            .$this->frame('TCOM', "\0Old composer")
            .$this->frame('TPE3', "\0Old performer")
            .$this->frame('COMM', "\0eng\0Old comment")
            .$describedComment
            .$privateFrame
            .$statisticsFrame;
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        file_put_contents($path, 'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.$audio);

        $metadata = (new Mp3TrackMetadataWriter(new Mp3Id3v2TagEditor(), new TestId3MetadataReader()))->write($path, [
            'title' => 'Änderung',
            'artistNames' => ['New artist', 'Guest'],
            'composers' => ['New composer'],
            'performers' => ['New performer'],
            'comment' => 'Ein Kommentar',
            'trackNumber' => 3,
            'discNumber' => 2,
            'year' => 2024,
        ]);

        $written = file_get_contents($path);
        $this->assertSame('Änderung', $metadata->title);
        $this->assertSame(3, $metadata->trackNumber);
        $this->assertSame(2, $metadata->discNumber);
        $this->assertSame(2024, $metadata->year);
        $this->assertEqualsCanonicalizing(['New artist', 'Guest'], $metadata->artists);
        $this->assertSame(['New composer'], $metadata->composers);
        $this->assertSame(['New performer'], $metadata->performers);
        $this->assertSame('Ein Kommentar', $metadata->comment);
        $this->assertStringContainsString($describedComment, $written);
        $this->assertStringContainsString($privateFrame, $written);
        $this->assertStringContainsString($statisticsFrame, $written);
        $this->assertStringEndsWith($audio, $written);
        $this->assertSame('3', $metadata->rawMetadata['comments']['track_number'][0]);
        $rawTrackNumber = $metadata->rawMetadata['id3v2']['TRCK'][0];
        $this->assertSame('3/12', trim(mb_convert_encoding($rawTrackNumber['data'], 'UTF-8', $rawTrackNumber['encoding']), "\0"));
        $this->assertSame('12', $metadata->rawMetadata['id3v2']['comments']['totaltracks'][0]);
        $this->assertSame('2/2', $metadata->rawMetadata['comments']['part_of_a_set'][0]);
    }

    public function test_it_writes_shared_album_fields_without_replacing_track_fields(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'album-track.mp3';
        $titleFrame = $this->frame('TIT2', "\0Track title");
        $trackFrame = $this->frame('TRCK', "\0".'4/10');
        $payload = $titleFrame
            .$trackFrame
            .$this->frame('TALB', "\0Old album")
            .$this->frame('TPE2', "\0Old artist")
            .$this->frame('TCON', "\0Old genre")
            .$this->frame('TYER', "\0".'2000');
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        $legacyLameGap = str_repeat("\x01", 1044);
        file_put_contents($path, 'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.$legacyLameGap.str_repeat("\xFF\xFB\x90\x64", 64));

        $metadata = (new Mp3TrackMetadataWriter(new Mp3Id3v2TagEditor(), new TestId3MetadataReader()))->write($path, [
            'albumTitle' => 'New album',
            'albumArtist' => 'New artist',
            'releaseYear' => 2025,
            'totalDiscs' => 3,
            'genres' => ['Heavy Metal', 'Doom'],
        ]);

        $written = file_get_contents($path);
        $this->assertStringContainsString($titleFrame, $written);
        $this->assertStringContainsString($trackFrame, $written);
        $this->assertSame('Track title', $metadata->title);
        $this->assertSame(4, $metadata->trackNumber);
        $this->assertSame('New album', $metadata->album);
        $this->assertSame('New artist', $metadata->albumArtist);
        $this->assertSame(2025, $metadata->originalReleaseYear);
        $this->assertSame(3, $metadata->discTotal);
        $this->assertEqualsCanonicalizing(['Heavy Metal', 'Doom'], $metadata->genres);
    }

    public function test_it_rewrites_a_padded_id3_tag_without_copying_the_audio_payload(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'padded.mp3';
        $audio = str_repeat("\xFF\xFB\x90\x64", 1024);
        $payload = $this->frame('TIT2', "\0Old title");
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        file_put_contents($path, 'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.$audio);
        $verifiedPath = null;

        (new Mp3Id3v2TagEditor())->write(
            $path,
            ['TIT2' => 'New title'],
            [],
            function (string $candidatePath) use (&$verifiedPath): void {
                $verifiedPath = $candidatePath;
            },
        );

        $this->assertSame($path, $verifiedPath);
        $this->assertStringEndsWith($audio, (string) file_get_contents($path));
        $this->assertSame([], glob($path.'.music-library-tag-*.bak') ?: []);
    }

    public function test_it_restores_the_original_tag_when_in_place_verification_fails(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'rollback.mp3';
        $payload = $this->frame('TIT2', "\0Old title");
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        $original = 'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload
            .str_repeat("\xFF\xFB\x90\x64", 64);
        file_put_contents($path, $original);

        try {
            (new Mp3Id3v2TagEditor())->write(
                $path,
                ['TIT2' => 'Rejected title'],
                [],
                static fn () => throw new \RuntimeException('Simulated verification failure.'),
            );
            $this->fail('Expected verification failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated verification failure.', $exception->getMessage());
        }

        $this->assertSame($original, file_get_contents($path));
        $this->assertSame([], glob($path.'.music-library-tag-*.bak') ?: []);
    }

    public function test_it_uses_the_full_copy_path_when_the_updated_tag_outgrows_existing_padding(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'growing.mp3';
        $audio = str_repeat("\xFF\xFB\x90\x64", 64);
        $payload = $this->frame('TIT2', "\0Old title");
        file_put_contents($path, 'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.$audio);
        $verifiedPath = null;

        (new Mp3Id3v2TagEditor())->write(
            $path,
            ['TIT2' => str_repeat('Larger title ', 300)],
            [],
            function (string $candidatePath) use (&$verifiedPath): void {
                $verifiedPath = $candidatePath;
            },
        );

        $this->assertNotSame($path, $verifiedPath);
        $this->assertStringEndsWith($audio, (string) file_get_contents($path));
    }

    public function test_it_preserves_the_mp3_extension_for_temporary_file_verification(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'track.mp3';
        $payload = $this->frame('TIT2', "\0Track title")
            .$this->frame('TCON', "\0Old genre");
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        file_put_contents($path, 'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.str_repeat("\xFF\xFB\x90\x64", 64));

        $metadata = (new Mp3TrackMetadataWriter(
            new Mp3Id3v2TagEditor(),
            new ExtensionSensitiveId3MetadataReader(),
        ))->write($path, ['genres' => ['Krautrock']]);

        $this->assertSame(['Krautrock'], $metadata->genres);
    }

    public function test_it_edits_id3v24_unsynchronized_tags_without_changing_preserved_frames_or_audio(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'unsynchronized.mp3';
        $privateFrame = $this->frameV4('PRIV', "owner\0\xFF\0\xE1data", "\0\x02");
        $audio = str_repeat("\xFF\xFB\x90\x64", 128);
        $payload = $this->frameV4('TIT2', chr(3).'Track title')
            .$this->frameV4('TCON', chr(3).'Old genre')
            .$privateFrame;
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        file_put_contents($path, 'ID3'.chr(4).chr(0).chr(0x80).$this->synchsafe(strlen($payload)).$payload.$audio);

        $metadata = (new Mp3TrackMetadataWriter(
            new Mp3Id3v2TagEditor(),
            new TestId3MetadataReader(),
        ))->write($path, ['genres' => ['Synthpop']]);

        $written = file_get_contents($path);
        $this->assertSame(0x80, ord($written[5]));
        $this->assertStringContainsString($privateFrame, $written);
        $this->assertStringEndsWith($audio, $written);
        $this->assertSame(['Synthpop'], $metadata->genres);
    }

    public function test_it_identifies_unsupported_id3v2_header_features_from_scanned_metadata(): void
    {
        $editor = new Mp3Id3v2TagEditor();

        $this->assertSame(
            Mp3Id3v2TagEditor::ISSUE_UNSYNCHRONIZATION,
            $editor->supportIssue(['id3v2' => ['flags' => ['unsynch' => true]]]),
        );
        $this->assertNull($editor->supportIssue([
            'id3v2' => ['majorversion' => 4, 'flags' => ['unsynch' => true]],
        ]));
        $this->assertSame(
            Mp3Id3v2TagEditor::ISSUE_EXTENDED_HEADER,
            $editor->supportIssue(['id3v2' => ['flags' => ['exthead' => true]]]),
        );
        $this->assertNull($editor->supportIssue(['id3v2' => ['flags' => []]]));
    }

    public function test_it_round_trips_a_literal_slash_in_an_id3v23_genre(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'slash-genre.mp3';
        $payload = $this->frame('TIT2', "\0Track title")
            .$this->frame('TCON', "\0Old genre");
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        file_put_contents($path, 'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.str_repeat("\xFF\xFB\x90\x64", 64));

        $metadata = (new Mp3TrackMetadataWriter(
            new Mp3Id3v2TagEditor(),
            new GetId3MetadataReader(new RawMetadataSanitizer()),
        ))->write($path, ['genres' => ['Singer/Songwriter']]);

        $this->assertSame(['Singer/Songwriter'], $metadata->genres);
    }

    private function textFrame(string $description, string $value): string
    {
        return $this->frame('TXXX', "\0{$description}\0{$value}");
    }

    private function frame(string $name, string $payload): string
    {
        return $name.pack('N', strlen($payload))."\0\0".$payload;
    }

    private function frameV4(string $name, string $payload, string $flags = "\0\0"): string
    {
        return $name.$this->synchsafe(strlen($payload)).$flags.$payload;
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
