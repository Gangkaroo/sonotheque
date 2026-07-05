<?php

namespace Tests\Unit;

use App\Music\Enrichment\MusicBrainzTagIdentifierReader;
use PHPUnit\Framework\TestCase;

class MusicBrainzTagIdentifierReaderTest extends TestCase
{
    public function test_it_reads_picard_identifiers_from_getid3_comments(): void
    {
        $identifiers = (new MusicBrainzTagIdentifierReader())->read([
            'comments' => [
                'musicbrainz_artistid' => ['5b11f4ce-a62d-471e-81fc-a69a8278c7da'],
                'musicbrainz_albumartistid' => ['65f4f0c5-ef9e-490c-aee3-909e7ae6b2ab'],
                'musicbrainz_albumid' => ['18d5d0ca-1107-4df2-9d51-df1c5fe57490'],
                'musicbrainz_releasegroupid' => ['c1f1a5fc-1aa7-32e8-92b4-a3f247e56f61'],
                'musicbrainz_trackid' => ['136f75c2-3898-4df1-8098-a6f9d17e5402'],
            ],
        ]);

        $this->assertSame('5b11f4ce-a62d-471e-81fc-a69a8278c7da', $identifiers['artist']);
        $this->assertSame('65f4f0c5-ef9e-490c-aee3-909e7ae6b2ab', $identifiers['albumArtist']);
        $this->assertSame('18d5d0ca-1107-4df2-9d51-df1c5fe57490', $identifiers['release']);
        $this->assertSame('c1f1a5fc-1aa7-32e8-92b4-a3f247e56f61', $identifiers['releaseGroup']);
        $this->assertSame('136f75c2-3898-4df1-8098-a6f9d17e5402', $identifiers['recording']);
    }

    public function test_it_ignores_invalid_identifiers(): void
    {
        $identifiers = (new MusicBrainzTagIdentifierReader())->read([
            'id3v2' => ['comments' => ['text' => ['musicbrainz_artistid' => ['not-an-mbid']]]],
        ]);

        $this->assertSame([], $identifiers);
    }
}
