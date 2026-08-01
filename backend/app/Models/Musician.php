<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'provider',
    'provider_reference',
    'name',
    'sort_name',
    'disambiguation',
    'entity_type',
])]
class Musician extends Model
{
    /** @return HasMany<AlbumMusicianCredit, $this> */
    public function credits(): HasMany
    {
        return $this->hasMany(AlbumMusicianCredit::class);
    }

    /** @return HasMany<ManualAlbumMusicianCredit, $this> */
    public function manualCredits(): HasMany
    {
        return $this->hasMany(ManualAlbumMusicianCredit::class);
    }
}
