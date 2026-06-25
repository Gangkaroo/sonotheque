<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'parent_id',
    'name',
])]
class PlaylistFolder extends Model
{
    /** @return BelongsTo<PlaylistFolder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(PlaylistFolder::class, 'parent_id');
    }

    /** @return HasMany<PlaylistFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(PlaylistFolder::class, 'parent_id');
    }

    /** @return HasMany<Playlist, $this> */
    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }
}
