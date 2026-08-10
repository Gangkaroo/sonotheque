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
    'discogs_personal_access_token',
    'discogs_username',
    'discogs_user_id',
    'discogs_connected_at',
    'online_information_enabled',
    'online_lyrics_enabled',
    'audio_intelligence_enabled',
    'audio_intelligence_validation_sample_size',
    'audio_intelligence_accelerator',
    'audio_similarity_reranking_enabled',
    'audio_similarity_tempo_influence',
    'audio_similarity_key_influence',
    'audio_similarity_intensity_influence',
    'playlist_export_format',
    'synchronize_playlists_to_files',
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
            'audio_intelligence_enabled' => false,
            'audio_intelligence_validation_sample_size' => 200,
            'audio_intelligence_accelerator' => self::configuredAudioIntelligenceAccelerator(),
            'audio_similarity_reranking_enabled' => false,
            'audio_similarity_tempo_influence' => 5,
            'audio_similarity_key_influence' => 3,
            'audio_similarity_intensity_influence' => 4,
            'playlist_export_format' => 'm3u8',
            'synchronize_playlists_to_files' => false,
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

    public function hasDiscogsConnection(): bool
    {
        return filled($this->discogs_personal_access_token)
            && filled($this->discogs_username)
            && $this->discogs_user_id !== null;
    }

    public function audioIntelligenceAccelerator(): string
    {
        return in_array($this->audio_intelligence_accelerator, ['cpu', 'cuda'], true)
            ? $this->audio_intelligence_accelerator
            : self::configuredAudioIntelligenceAccelerator();
    }

    /**
     * @return array{enabled: bool, tempoInfluence: int, keyInfluence: int, intensityInfluence: int}
     */
    public function audioSimilarityReranking(): array
    {
        return [
            'enabled' => (bool) $this->audio_similarity_reranking_enabled,
            'tempoInfluence' => (int) $this->audio_similarity_tempo_influence,
            'keyInfluence' => (int) $this->audio_similarity_key_influence,
            'intensityInfluence' => (int) $this->audio_similarity_intensity_influence,
        ];
    }

    private static function configuredAudioIntelligenceAccelerator(): string
    {
        $configured = strtolower((string) config(
            'sonotheque.audio_intelligence.accelerator',
            'cpu',
        ));

        return in_array($configured, ['cpu', 'cuda'], true) ? $configured : 'cpu';
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
            'discogs_personal_access_token' => 'encrypted',
            'discogs_user_id' => 'integer',
            'discogs_connected_at' => 'immutable_datetime',
            'online_information_enabled' => 'boolean',
            'online_lyrics_enabled' => 'boolean',
            'audio_intelligence_enabled' => 'boolean',
            'audio_intelligence_validation_sample_size' => 'integer',
            'audio_similarity_reranking_enabled' => 'boolean',
            'audio_similarity_tempo_influence' => 'integer',
            'audio_similarity_key_influence' => 'integer',
            'audio_similarity_intensity_influence' => 'integer',
            'synchronize_playlists_to_files' => 'boolean',
        ];
    }
}
