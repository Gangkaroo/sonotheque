<?php

namespace Tests\Unit;

use App\Music\Streaming\ByteRangeParser;
use App\Music\Streaming\InvalidByteRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ByteRangeParserTest extends TestCase
{
    #[DataProvider('ranges')]
    public function test_it_parses_supported_byte_ranges(string $header, int $start, int $end): void
    {
        $range = (new ByteRangeParser())->parse($header, 10);

        $this->assertSame($start, $range?->start);
        $this->assertSame($end, $range?->end);
    }

    public static function ranges(): array
    {
        return [
            'bounded' => ['bytes=2-5', 2, 5],
            'open ended' => ['bytes=6-', 6, 9],
            'suffix' => ['bytes=-3', 7, 9],
            'oversized end' => ['bytes=8-99', 8, 9],
            'oversized suffix' => ['bytes=-99', 0, 9],
        ];
    }

    public function test_it_limits_open_ended_ranges_when_a_maximum_length_is_given(): void
    {
        $range = (new ByteRangeParser())->parse('bytes=2-', 10, 4);

        $this->assertSame(2, $range?->start);
        $this->assertSame(5, $range?->end);
    }

    #[DataProvider('invalidRanges')]
    public function test_it_rejects_invalid_or_multiple_ranges(string $header): void
    {
        $this->expectException(InvalidByteRange::class);

        (new ByteRangeParser())->parse($header, 10);
    }

    public static function invalidRanges(): array
    {
        return [
            'wrong unit' => ['items=0-1'],
            'multiple' => ['bytes=0-1,3-4'],
            'empty' => ['bytes=-'],
            'zero suffix' => ['bytes=-0'],
            'past end' => ['bytes=10-'],
            'reverse' => ['bytes=5-2'],
        ];
    }
}
