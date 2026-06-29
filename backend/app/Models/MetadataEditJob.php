<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'track_id',
    'album_id',
    'media_file_id',
    'type',
    'status',
    'fingerprint',
    'requested_changes',
    'preview',
    'error',
    'started_at',
    'finished_at',
    'total_items',
    'processed_items',
    'succeeded_items',
    'failed_items',
])]
class MetadataEditJob extends Model
{
    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return HasMany<MetadataEditItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MetadataEditItem::class);
    }

    protected function casts(): array
    {
        return [
            'requested_changes' => 'array',
            'preview' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'total_items' => 'integer',
            'processed_items' => 'integer',
            'succeeded_items' => 'integer',
            'failed_items' => 'integer',
        ];
    }
}
