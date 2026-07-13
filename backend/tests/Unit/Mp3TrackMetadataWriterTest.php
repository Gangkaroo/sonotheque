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
        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sonotheque-track-writer-'.bin2hex(random_bytes(6));
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
        $this->assertSame([], glob($path.'.sonotheque-tag-*.bak') ?: []);
    }

    public function test_it_discards_empty_frames_while_preserving_non_empty_frames_and_audio(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'empty-frames.mp3';
        $emptyUrlFrame = $this->frame('WOAF', '');
        $privateFrame = $this->frame('PRIV', "owner\0data");
        $audio = str_repeat("\xFF\xFB\x90\x64", 128);
        $payload = $emptyUrlFrame
            .$this->frame('TIT2', "\0Track title")
            .$this->frame('TCON', "\0Old genre")
            .$privateFrame;
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        file_put_contents($path, 'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.$audio);

        $metadata = (new Mp3TrackMetadataWriter(
            new Mp3Id3v2TagEditor(),
            new GetId3MetadataReader(new RawMetadataSanitizer()),
        ))->write($path, ['genres' => ['Progressive Rock']]);

        $written = (string) file_get_contents($path);
        $this->assertSame(['Progressive Rock'], $metadata->genres);
        $this->assertStringNotContainsString($emptyUrlFrame, $written);
        $this->assertStringContainsString($privateFrame, $written);
        $this->assertStringEndsWith($audio, $written);
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
        $this->assertSame([], glob($path.'.sonotheque-tag-*.bak') ?: []);
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

    public function test_it_converts_id3v22_to_v23_while_preserving_comments_picture_and_audio(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'legacy-v22.mp3';
        $picture = "\x89PNG\r\n\x1A\nlegacy-picture";
        $describedComment = "\0engSOURCE\0Keep this source";
        $emptyUrlFrame = $this->frameV22('WAF', '');
        $audio = str_repeat("\xFF\xFB\x90\x64", 128);
        $payload = $emptyUrlFrame
            .$this->frameV22('TT2', "\0Legacy title")
            .$this->frameV22('TCO', "\0Old genre")
            .$this->frameV22('COM', $describedComment)
            .$this->frameV22('PIC', "\0PNG".chr(3)."\0".$picture);
        $payload .= str_repeat("\0", 4096 - strlen($payload));
        file_put_contents($path, 'ID3'.chr(2).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.$audio);

        $metadata = (new Mp3TrackMetadataWriter(
            new Mp3Id3v2TagEditor(),
            new GetId3MetadataReader(new RawMetadataSanitizer()),
        ))->write($path, ['genres' => ['Psychedelic Rock']]);

        $written = file_get_contents($path);
        $this->assertSame(3, ord($written[3]));
        $this->assertSame(['Psychedelic Rock'], $metadata->genres);
        $this->assertStringContainsString('COMM', $written);
        $this->assertStringContainsString('Keep this source', $written);
        $this->assertStringContainsString('APIC', $written);
        $this->assertStringContainsString("image/png\0", $written);
        $this->assertStringContainsString($picture, $written);
        $this->assertStringNotContainsString($emptyUrlFrame, $written);
        $this->assertStringEndsWith($audio, $written);
    }

    public function test_it_ignores_described_technical_comments_when_clearing_the_track_comment(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'technical-comments.mp3';
        $technicalComment = $this->frame('COMM', "\0engiTunPGAP\0".chr(0).chr(0));
        $payload = $this->frame('TIT2', "\0Track title")
            .$technicalComment
            .$this->frame('COMM', "\0eng\0User comment");
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        file_put_contents($path, 'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload.str_repeat("\xFF\xFB\x90\x64", 64));

        $metadata = (new Mp3TrackMetadataWriter(
            new Mp3Id3v2TagEditor(),
            new GetId3MetadataReader(new RawMetadataSanitizer()),
        ))->write($path, ['comment' => null]);

        $this->assertNull($metadata->comment);
        $this->assertStringContainsString($technicalComment, (string) file_get_contents($path));
    }

    public function test_it_clears_the_id3v1_comment_without_losing_the_legacy_track_number(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'legacy-comment.mp3';
        $payload = $this->frame('TIT2', "\0Track title")
            .$this->frame('COMM', "\0eng\0User comment");
        $payload .= str_repeat("\0", 2048 - strlen($payload));
        $id3v1 = 'TAG'
            .str_pad('Legacy title', 30, "\0")
            .str_pad('Legacy artist', 30, "\0")
            .str_pad('Legacy album', 30, "\0")
            .'2002'
            .str_pad('www.NewAlbumReleases.net', 28, "\0")
            ."\0".chr(7).chr(13);
        file_put_contents(
            $path,
            'ID3'.chr(3).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload
                .str_repeat("\xFF\xFB\x90\x64", 64)
                .$id3v1,
        );

        $metadata = (new Mp3TrackMetadataWriter(
            new Mp3Id3v2TagEditor(),
            new GetId3MetadataReader(new RawMetadataSanitizer()),
        ))->write($path, ['comment' => null]);

        $writtenTag = substr((string) file_get_contents($path), -128);
        $this->assertNull($metadata->comment);
        $this->assertSame(substr($id3v1, 0, 97), substr($writtenTag, 0, 97));
        $this->assertSame(str_repeat("\0", 28), substr($writtenTag, 97, 28));
        $this->assertSame("\0".chr(7), substr($writtenTag, 125, 2));
        $this->assertSame(chr(13), $writtenTag[127]);
    }

    public function test_it_rejects_an_unknown_id3v22_frame_without_changing_the_file(): void
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'legacy-unknown.mp3';
        $payload = $this->frameV22('ZZZ', "\0unknown").str_repeat("\0", 128);
        $original = 'ID3'.chr(2).chr(0).chr(0).$this->synchsafe(strlen($payload)).$payload
            .str_repeat("\xFF\xFB\x90\x64", 32);
        file_put_contents($path, $original);

        try {
            (new Mp3Id3v2TagEditor())->write($path, ['TIT2' => 'New title'], [], static fn () => null);
            $this->fail('Expected an unsupported legacy frame error.');
        } catch (\App\Music\PlaybackStatistics\UnsupportedPlaybackStatisticsTagFormat $exception) {
            $this->assertStringContainsString('frame [ZZZ]', $exception->getMessage());
        }

        $this->assertSame($original, file_get_contents($path));
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
        $this->assertSame(
            Mp3Id3v2TagEditor::ISSUE_COMPRESSION,
            $editor->supportIssue([
                'id3v2' => ['majorversion' => 2, 'flags' => ['compression' => true]],
            ]),
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

    private function frameV22(string $name, string $payload): string
    {
        $length = strlen($payload);

        return $name.pack('C3', ($length >> 16) & 0xFF, ($length >> 8) & 0xFF, $length & 0xFF).$payload;
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
