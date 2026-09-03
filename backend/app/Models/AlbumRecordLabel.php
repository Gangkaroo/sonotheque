<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'album_id',
    'record_label_id',
    'catalog_number',
    'catalog_number_hash',
    'source',
    'source_reference',
])]
class AlbumRecordLabel extends Model
{
    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return BelongsTo<RecordLabel, $this> */
    public function recordLabel(): BelongsTo
    {
        return $this->belongsTo(RecordLabel::class);
    }
}
