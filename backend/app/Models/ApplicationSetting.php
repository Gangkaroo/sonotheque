<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'import_play_statistics_from_tags',
    'export_play_statistics_to_tags',
])]
class ApplicationSetting extends Model
{
    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create([
            'import_play_statistics_from_tags' => false,
            'export_play_statistics_to_tags' => false,
        ]);
    }

    protected function casts(): array
    {
        return [
            'import_play_statistics_from_tags' => 'boolean',
            'export_play_statistics_to_tags' => 'boolean',
        ];
    }
}
