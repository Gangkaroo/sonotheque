<?php

namespace Tests\Unit;

use App\Music\Scanning\RawMetadataSanitizer;
use PHPUnit\Framework\TestCase;

class RawMetadataSanitizerTest extends TestCase
{
    public function test_it_replaces_values_that_postgresql_jsonb_cannot_store(): void
    {
        $metadata = (new RawMetadataSanitizer())->sanitize([
            'comments' => [
                "\xFF\xFEi\0T\0u\0n\0P\0G\0A\0P\0" => 'binary key',
                'music_cd_identifier' => ["\x02:\x01F\0\x10"],
                'title' => ["Valid \xFF title"],
            ],
            'mpeg' => [
                'audio' => [
                    'bitrate' => INF,
                    'compression_ratio' => NAN,
                ],
            ],
        ]);
        $json = json_encode($metadata, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('[binary key omitted: 18 bytes]', $metadata['comments']);
        $this->assertSame('[binary data omitted: 6 bytes]', $metadata['comments']['music_cd_identifier'][0]);
        $this->assertSame('[non-finite number omitted]', $metadata['mpeg']['audio']['bitrate']);
        $this->assertSame('[non-finite number omitted]', $metadata['mpeg']['audio']['compression_ratio']);
        $this->assertStringNotContainsString('\\u0000', $json);
        $this->assertSame('Valid � title', $metadata['comments']['title'][0]);
    }
}
