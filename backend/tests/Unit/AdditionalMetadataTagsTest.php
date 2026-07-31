<?php

namespace Tests\Unit;

use App\Music\Metadata\AdditionalMetadataTags;
use PHPUnit\Framework\TestCase;

class AdditionalMetadataTagsTest extends TestCase
{
    public function test_it_extracts_additional_frames_without_listing_standard_metadata_or_artwork(): void
    {
        $tags = (new AdditionalMetadataTags())->extract([
            'id3v2' => [
                'comments' => [
                    'source' => ['Bandcamp'],
                ],
                'APIC' => [
                    ['framenamelong' => 'Attached picture', 'datalength' => 4096],
                ],
                'TIT2' => [
                    ['framenamelong' => 'Title/songname/content description', 'data' => 'Track'],
                ],
                'COMM' => [
                    ['framenamelong' => 'Comments', 'description' => '', 'data' => 'Ordinary comment'],
                    ['framenamelong' => 'Comments', 'description' => 'iTunNORM', 'datalength' => 32],
                ],
                'RVA2' => [
                    ['framenamelong' => 'Relative volume adjustment (2)', 'datalength' => 5],
                ],
                'TXXX' => [
                    [
                        'framenamelong' => 'User defined text information frame',
                        'description' => 'Source',
                        'data' => 'Bandcamp',
                        'datalength' => 18,
                    ],
                ],
            ],
        ]);

        $this->assertSame(['COMM:ITUNNORM', 'RVA2', 'TXXX:SOURCE'], array_column($tags, 'key'));
        $this->assertSame('iTunNORM', $tags[0]['name']);
        $this->assertSame([], $tags[0]['values']);
        $this->assertSame('Relative volume adjustment (2)', $tags[1]['name']);
        $this->assertSame(5, $tags[1]['sizeBytes']);
        $this->assertSame(['Bandcamp'], $tags[2]['values']);
    }
}
