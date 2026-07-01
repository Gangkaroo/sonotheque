<?php

namespace App\Models;

use App\Enums\ArtworkSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'source_type',
    'source_relative_path',
    'thumbnail_path',
    'mime_type',
    'width',
    'height',
    'checksum',
])]
class Artwork extends Model
{
    protected $table = 'artwork';

    /** @return HasMany<Album, $this> */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    protected function casts(): array
    {
        return [
            'source_type' => ArtworkSource::class,
        ];
    }
}
