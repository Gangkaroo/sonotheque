<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'playlist_folder_id',
    'name',
    'description',
])]
class Playlist extends Model
{
    /** @return BelongsTo<PlaylistFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(PlaylistFolder::class, 'playlist_folder_id');
    }

    /** @return HasMany<PlaylistItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PlaylistItem::class)->orderBy('position');
    }
}
