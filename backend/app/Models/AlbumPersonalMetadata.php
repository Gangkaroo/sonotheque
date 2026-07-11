<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'album_id',
    'purchase_source',
    'purchase_date',
    'has_physical_copy',
    'physical_format',
    'notes',
])]
class AlbumPersonalMetadata extends Model
{
    protected $table = 'album_personal_metadata';

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'has_physical_copy' => 'boolean',
        ];
    }
}
