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
        $this->assertFalse($tags[0]['playbackStatistic']);
        $this->assertSame('Relative volume adjustment (2)', $tags[1]['name']);
        $this->assertSame(5, $tags[1]['sizeBytes']);
        $this->assertSame(['Bandcamp'], $tags[2]['values']);
    }

    public function test_it_identifies_playback_statistics_frames(): void
    {
        $metadata = [
            'id3v2' => [
                'PCNT' => [['framenamelong' => 'Play counter', 'datalength' => 4]],
                'TXXX' => [
                    ['description' => 'PLAY_COUNT', 'data' => '12'],
                    ['description' => 'FIRST_PLAYED_TIMESTAMP', 'data' => '123'],
                    ['description' => 'LAST_PLAYED_TIMESTAMP', 'data' => '456'],
                    ['description' => 'SOURCE', 'data' => 'Bandcamp'],
                ],
            ],
        ];

        $tags = (new AdditionalMetadataTags())->extract($metadata);

        $this->assertSame(
            ['PCNT', 'TXXX:FIRST_PLAYED_TIMESTAMP', 'TXXX:LAST_PLAYED_TIMESTAMP', 'TXXX:PLAY_COUNT'],
            (new AdditionalMetadataTags())->playbackStatisticKeys($metadata),
        );
        $this->assertFalse(collect($tags)->firstWhere('key', 'TXXX:SOURCE')['playbackStatistic']);
    }

    public function test_it_excludes_managed_label_and_catalogue_fields_from_custom_tag_removal(): void
    {
        $metadata = ['id3v2' => [
            'TPUB' => [['data' => 'Label']],
            'TXXX' => array_map(
                static fn (string $name): array => ['description' => $name, 'data' => 'Value'],
                ['LABEL', 'Publisher', 'ORGANIZATION', 'Record Label', 'CATALOG', 'CATALOGNUMBER',
                    'Catalog No', 'Catalog_Nr', 'Catalogue Number', 'CatalogueNo', 'SOURCE'],
            ),
            'COMM' => [['description' => 'Publisher', 'data' => 'Unrelated described comment']],
        ]];

        $this->assertSame(['COMM:PUBLISHER', 'TXXX:SOURCE'], (new AdditionalMetadataTags())->keys($metadata));
    }

    public function test_it_identifies_rating_frames(): void
    {
        $metadata = [
            'id3v2' => [
                'POPM' => [['email' => 'rating@sonotheque.local', 'rating' => 196]],
                'TXXX' => [
                    ['description' => 'RATING', 'data' => '4'],
                    ['description' => 'SONOTHEQUE_ALBUM_RATING', 'data' => '4.5'],
                    ['description' => 'SOURCE', 'data' => 'Bandcamp'],
                ],
            ],
        ];

        $this->assertSame(
            ['POPM', 'TXXX:RATING', 'TXXX:SONOTHEQUE_ALBUM_RATING'],
            (new AdditionalMetadataTags())->ratingKeys($metadata),
        );
    }

    public function test_it_keeps_grouped_user_defined_text_values_with_their_own_frames(): void
    {
        $metadata = [
            'id3v2' => [
                'comments' => [
                    'text' => [
                        'WWW' => 'GetMetal.CLUB',
                        'BANDCAMP_URL' => 'https://example.bandcamp.com/album/reflections',
                        'BANDCAMP_ALBUM_ID' => '1529389541',
                    ],
                ],
                'TXXX' => [
                    [
                        'description' => 'WWW',
                        'data' => '[binary data omitted: 28 bytes]',
                        'framenameshort' => 'text',
                    ],
                    [
                        'description' => 'BANDCAMP_URL',
                        'data' => '[binary data omitted: 118 bytes]',
                        'framenameshort' => 'text',
                    ],
                    [
                        'description' => 'BANDCAMP_ALBUM_ID',
                        'data' => '[binary data omitted: 22 bytes]',
                        'framenameshort' => 'text',
                    ],
                ],
            ],
        ];

        $tags = collect((new AdditionalMetadataTags())->extract($metadata))->keyBy('key');

        $this->assertSame(['GetMetal.CLUB'], $tags['TXXX:WWW']['values']);
        $this->assertSame(
            ['https://example.bandcamp.com/album/reflections'],
            $tags['TXXX:BANDCAMP_URL']['values'],
        );
        $this->assertSame(['1529389541'], $tags['TXXX:BANDCAMP_ALBUM_ID']['values']);
    }
}
