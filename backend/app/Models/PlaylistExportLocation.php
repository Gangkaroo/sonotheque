<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'path',
    'path_hash',
    'is_default',
])]
class PlaylistExportLocation extends Model
{
    /** @var list<string> */
    protected $hidden = ['path_hash'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
