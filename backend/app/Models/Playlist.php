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
    'playlist_export_location_id',
    'playlist_export_root_path',
    'playlist_export_relative_path',
    'playlist_export_synced_at',
    'playlist_export_sync_pending_at',
    'playlist_export_sync_error',
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

    /** @return HasMany<PlaylistOrderSnapshot, $this> */
    public function orderSnapshots(): HasMany
    {
        return $this->hasMany(PlaylistOrderSnapshot::class);
    }

    protected function casts(): array
    {
        return [
            'playlist_export_synced_at' => 'immutable_datetime',
            'playlist_export_sync_pending_at' => 'immutable_datetime',
        ];
    }
}
