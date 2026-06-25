<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
])]
class PlaylistFolder extends Model
{
    /** @return HasMany<Playlist, $this> */
    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }
}
