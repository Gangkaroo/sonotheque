<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'import_play_statistics_from_tags',
    'export_play_statistics_to_tags',
    'metadata_backups_enabled',
    'metadata_backup_path',
    'metadata_backup_retention_days',
])]
class ApplicationSetting extends Model
{
    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create([
            'import_play_statistics_from_tags' => false,
            'export_play_statistics_to_tags' => false,
            'metadata_backups_enabled' => false,
            'metadata_backup_path' => config('music-library.metadata_backups.default_path'),
            'metadata_backup_retention_days' => config('music-library.metadata_backups.default_retention_days'),
        ]);
    }

    public function synchronizesPlaybackStatisticsWithTags(): bool
    {
        return $this->import_play_statistics_from_tags && $this->export_play_statistics_to_tags;
    }

    protected function casts(): array
    {
        return [
            'import_play_statistics_from_tags' => 'boolean',
            'export_play_statistics_to_tags' => 'boolean',
            'metadata_backups_enabled' => 'boolean',
            'metadata_backup_retention_days' => 'integer',
        ];
    }
}
