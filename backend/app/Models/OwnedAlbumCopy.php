<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'album_id',
    'is_physical',
    'physical_format',
    'purchase_source',
    'purchase_date',
    'purchase_price_amount',
    'purchase_price_currency',
    'media_condition',
    'sleeve_condition',
    'notes',
    'provider',
    'external_release_id',
    'external_master_id',
    'external_collection_instance_id',
    'external_folder_id',
    'provider_synced_at',
])]
class OwnedAlbumCopy extends Model
{
    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return HasOne<AlbumDiscogsMusicianSource, $this> */
    public function discogsMusicianSource(): HasOne
    {
        return $this->hasOne(AlbumDiscogsMusicianSource::class);
    }

    protected function casts(): array
    {
        return [
            'is_physical' => 'boolean',
            'purchase_date' => 'date',
            'purchase_price_amount' => 'decimal:2',
            'provider_synced_at' => 'immutable_datetime',
        ];
    }
}
