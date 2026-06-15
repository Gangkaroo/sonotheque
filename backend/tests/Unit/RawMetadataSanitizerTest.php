<?php

namespace Tests\Unit;

use App\Music\Scanning\RawMetadataSanitizer;
use PHPUnit\Framework\TestCase;

class RawMetadataSanitizerTest extends TestCase
{
    public function test_it_replaces_binary_strings_that_postgresql_jsonb_cannot_store(): void
    {
        $metadata = (new RawMetadataSanitizer)->sanitize([
            'comments' => [
                'music_cd_identifier' => ["\x02:\x01F\0\x10"],
                'title' => ["Valid \xFF title"],
            ],
        ]);
        $json = json_encode($metadata, JSON_THROW_ON_ERROR);

        $this->assertSame('[binary data omitted: 6 bytes]', $metadata['comments']['music_cd_identifier'][0]);
        $this->assertStringNotContainsString('\\u0000', $json);
        $this->assertSame('Valid � title', $metadata['comments']['title'][0]);
    }
}
