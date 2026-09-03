<?php

namespace Tests\Unit;

use App\Music\Catalog\RecordLabelTagReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecordLabelTagReaderTest extends TestCase
{
    #[Test]
    public function it_reads_common_label_aliases_and_pairs_catalog_numbers(): void
    {
        $imported = (new RecordLabelTagReader())->read([
            'comments' => [
                'LABEL' => ['4AD', 'Elektra'],
                'CATALOGNUMBER' => ['CAD 3014 CD', '9 61422-2'],
            ],
        ]);

        $this->assertCount(2, $imported->items);
        $this->assertSame('4AD', $imported->items[0]->name);
        $this->assertSame('CAD 3014 CD', $imported->items[0]->catalogNumber);
        $this->assertSame('Elektra', $imported->items[1]->name);
        $this->assertSame('9 61422-2', $imported->items[1]->catalogNumber);
    }

    #[Test]
    public function it_reads_standard_id3_and_custom_publisher_frames_without_duplicates(): void
    {
        $imported = (new RecordLabelTagReader())->read([
            'comments' => ['publisher' => ['InsideOut Music']],
            'id3v2' => [
                'TPUB' => [[
                    'encoding' => 'UTF-8',
                    'data' => 'InsideOut Music',
                ]],
                'TXXX' => [[
                    'description' => 'CATALOGNUMBER',
                    'encoding' => 'UTF-8',
                    'data' => 'IOMCD 123',
                ]],
            ],
        ]);

        $this->assertCount(1, $imported->items);
        $this->assertSame('InsideOut Music', $imported->items[0]->name);
        $this->assertSame('IOMCD 123', $imported->items[0]->catalogNumber);
    }

    #[Test]
    public function it_accepts_vorbis_organization_and_ape_label_aliases(): void
    {
        $reader = new RecordLabelTagReader();
        $vorbis = $reader->read([
            'vorbiscomment' => [
                'comments' => ['ORGANIZATION' => ['Constellation Records']],
            ],
        ]);
        $ape = $reader->read([
            'ape' => [
                'items' => ['label' => ['data' => ['Mute Records']]],
            ],
        ]);

        $this->assertSame('Constellation Records', $vorbis->items[0]->name);
        $this->assertSame('Mute Records', $ape->items[0]->name);
    }

    #[Test]
    public function it_preserves_positional_id3_pairs_when_one_label_has_no_catalog_number(): void
    {
        $imported = (new RecordLabelTagReader())->read([
            'id3v2' => [
                'TPUB' => [
                    ['encoding' => 'UTF-8', 'data' => 'Label One'],
                    ['encoding' => 'UTF-8', 'data' => 'Label Two'],
                ],
                'TXXX' => [
                    ['description' => 'CATALOGNUMBER', 'encoding' => 'UTF-8', 'data' => 'ONE-1'],
                    ['description' => 'CATALOGNUMBER', 'encoding' => 'UTF-8', 'data' => ''],
                ],
            ],
        ]);

        $this->assertCount(2, $imported->items);
        $this->assertSame('ONE-1', $imported->items[0]->catalogNumber);
        $this->assertNull($imported->items[1]->catalogNumber);
    }

    #[Test]
    public function it_reads_decoded_id3_comments_when_utf16_frame_bytes_were_sanitized(): void
    {
        $imported = (new RecordLabelTagReader())->read([
            'id3v2' => [
                'comments' => [
                    'publisher' => ['Nuclear Blast GmbH'],
                    'text' => ['CATALOGNUMBER' => 'NB 1515-0'],
                ],
                'TPUB' => [[
                    'encoding' => 'UTF-16',
                    'data' => '[binary data omitted: 38 bytes]',
                ]],
                'TXXX' => [[
                    'description' => 'CATALOGNUMBER',
                    'encoding' => 'UTF-16',
                    'data' => '[binary data omitted: 18 bytes]',
                ]],
            ],
        ]);

        $this->assertCount(1, $imported->items);
        $this->assertSame('Nuclear Blast GmbH', $imported->items[0]->name);
        $this->assertSame('NB 1515-0', $imported->items[0]->catalogNumber);
    }
}
