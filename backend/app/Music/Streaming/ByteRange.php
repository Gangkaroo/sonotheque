<?php

namespace App\Music\Streaming;

final readonly class ByteRange
{
    public function __construct(
        public int $start,
        public int $end,
    ) {
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }
}
