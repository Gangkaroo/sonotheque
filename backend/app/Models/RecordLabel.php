<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'normalized_name',
])]
class RecordLabel extends Model
{
    /** @return HasMany<AlbumRecordLabel, $this> */
    public function albumAssignments(): HasMany
    {
        return $this->hasMany(AlbumRecordLabel::class);
    }
}
