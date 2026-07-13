<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'setup_step',
    'setup_completed',
    'import_play_statistics_from_tags',
    'export_play_statistics_to_tags',
    'metadata_backups_enabled',
    'metadata_backup_path',
    'metadata_backup_retention_days',
    'lastfm_scrobbling_enabled',
    'lastfm_api_key',
    'lastfm_api_secret',
    'lastfm_session_key',
    'lastfm_username',
    'lastfm_auth_token',
    'lastfm_auth_token_expires_at',
    'online_information_enabled',
    'online_lyrics_enabled',
])]
class ApplicationSetting extends Model
{
    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create([
            'setup_step' => 1,
            'setup_completed' => false,
            'import_play_statistics_from_tags' => false,
            'export_play_statistics_to_tags' => false,
            'metadata_backups_enabled' => false,
            'metadata_backup_path' => config('sonotheque.metadata_backups.default_path'),
            'metadata_backup_retention_days' => config('sonotheque.metadata_backups.default_retention_days'),
            'lastfm_scrobbling_enabled' => false,
            'online_information_enabled' => false,
            'online_lyrics_enabled' => false,
        ]);
    }

    public function synchronizesPlaybackStatisticsWithTags(): bool
    {
        return $this->import_play_statistics_from_tags && $this->export_play_statistics_to_tags;
    }

    public function hasLastFmCredentials(): bool
    {
        return filled($this->lastfm_api_key) && filled($this->lastfm_api_secret);
    }

    public function hasLastFmSession(): bool
    {
        return $this->hasLastFmCredentials() && filled($this->lastfm_session_key);
    }

    public function scrobblesToLastFm(): bool
    {
        return $this->lastfm_scrobbling_enabled && $this->hasLastFmSession();
    }

    protected function casts(): array
    {
        return [
            'setup_step' => 'integer',
            'setup_completed' => 'boolean',
            'import_play_statistics_from_tags' => 'boolean',
            'export_play_statistics_to_tags' => 'boolean',
            'metadata_backups_enabled' => 'boolean',
            'metadata_backup_retention_days' => 'integer',
            'lastfm_scrobbling_enabled' => 'boolean',
            'lastfm_api_secret' => 'encrypted',
            'lastfm_session_key' => 'encrypted',
            'lastfm_auth_token' => 'encrypted',
            'lastfm_auth_token_expires_at' => 'immutable_datetime',
            'online_information_enabled' => 'boolean',
            'online_lyrics_enabled' => 'boolean',
        ];
    }
}
