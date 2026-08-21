<?php

namespace App\Music\Catalog;

use App\Models\Genre;

class GenreResolver
{
    public function resolve(string $name): Genre
    {
        $genre = Genre::query()->whereRaw('LOWER(name) = LOWER(?)', [$name])->first();
        if ($genre !== null) {
            return $genre;
        }

        $timestamp = now();
        Genre::query()->insertOrIgnore([
            'name' => $name,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Genre::query()->whereRaw('LOWER(name) = LOWER(?)', [$name])->firstOrFail();
    }
}
