<?php

namespace App\Music\Scanning;

use Illuminate\Support\Str;

class ArtistName
{
    public function browseInitial(string $name): string
    {
        $ascii = Str::ascii(trim($name));
        $initial = strtoupper(substr($ascii, 0, 1));

        return preg_match('/^[A-Z]$/', $initial) === 1 ? $initial : '#';
    }
}
