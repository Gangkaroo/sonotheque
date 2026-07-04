<?php

$trustedHosts = array_values(array_filter(array_map(
    static fn (string $host): string => trim($host),
    explode(',', (string) env('MUSIC_LIBRARY_TRUSTED_HOSTS', 'localhost,127.0.0.1,::1')),
)));
$enrichmentCaBundle = env('ENRICHMENT_CA_BUNDLE') ?: env('LASTFM_CA_BUNDLE');

return [
    'scan_memory_limit' => env('SCAN_MEMORY_LIMIT', '1024M'),
    'scan_stale_after_minutes' => (int) env('SCAN_STALE_AFTER_MINUTES', 15),
    'counted_play_minimum_track_seconds' => (int) env('PLAY_STATISTICS_MINIMUM_TRACK_SECONDS', 30),
    'counted_play_maximum_threshold_seconds' => (int) env('PLAY_STATISTICS_MAXIMUM_THRESHOLD_SECONDS', 240),
    'play_statistics_sync_delay_seconds' => (int) env('PLAY_STATISTICS_SYNC_DELAY_SECONDS', 30),
    'audio_stream_open_ended_range_bytes' => (int) env('AUDIO_STREAM_OPEN_ENDED_RANGE_BYTES', 2 * 1024 * 1024),

    'lastfm' => [
        'api_url' => env('LASTFM_API_URL', 'https://ws.audioscrobbler.com/2.0/'),
        'auth_url' => env('LASTFM_AUTH_URL', 'https://www.last.fm/api/auth/'),
        'timeout_seconds' => (int) env('LASTFM_TIMEOUT_SECONDS', 10),
        'proxy' => env('LASTFM_PROXY', ''),
        'ca_bundle' => env('LASTFM_CA_BUNDLE') ?: $enrichmentCaBundle,
    ],

    'enrichment' => [
        'user_agent' => env('ENRICHMENT_USER_AGENT', 'MusicLibrary/0.1 (local music library application)'),
        'ready_cache_days' => (int) env('ENRICHMENT_READY_CACHE_DAYS', 30),
        'stale_cache_days' => (int) env('ENRICHMENT_STALE_CACHE_DAYS', 7),
        'not_found_cache_hours' => (int) env('ENRICHMENT_NOT_FOUND_CACHE_HOURS', 24),
        'error_retry_minutes' => (int) env('ENRICHMENT_ERROR_RETRY_MINUTES', 15),
        'max_error_retry_minutes' => (int) env('ENRICHMENT_MAX_ERROR_RETRY_MINUTES', 360),
        'lock_seconds' => (int) env('ENRICHMENT_LOCK_SECONDS', 30),
        'lock_wait_seconds' => (int) env('ENRICHMENT_LOCK_WAIT_SECONDS', 12),
        'providers' => [
            'lastfm' => [
                'max_requests_per_minute' => (int) env('LASTFM_ENRICHMENT_REQUESTS_PER_MINUTE', 30),
            ],
            'lrclib' => [
                'max_requests_per_minute' => (int) env('LRCLIB_REQUESTS_PER_MINUTE', 60),
            ],
        ],
        'lrclib' => [
            'api_url' => env('LRCLIB_API_URL', 'https://lrclib.net/api'),
            'timeout_seconds' => (int) env('LRCLIB_TIMEOUT_SECONDS', 20),
            'proxy' => env('LRCLIB_PROXY', ''),
            'ca_bundle' => env('LRCLIB_CA_BUNDLE') ?: $enrichmentCaBundle,
        ],
    ],

    'metadata_backups' => [
        'default_path' => env('METADATA_BACKUP_PATH', storage_path('app/metadata-backups')),
        'default_retention_days' => (int) env('METADATA_BACKUP_RETENTION_DAYS', 30),
    ],

    'lan' => [
        'enabled' => (bool) env('MUSIC_LIBRARY_LAN_ENABLED', false),
        'admin_token' => env('MUSIC_LIBRARY_ADMIN_TOKEN'),
        'trusted_hosts' => array_map(
            static fn (string $host): string => '^'.preg_quote($host, '/').'$',
            $trustedHosts,
        ),
        'protected_paths' => [
            'api/folders*',
            'api/library_roots*',
            'api/scan_runs*',
            'api/settings*',
            'api/tracks/*/metadata*',
            'api/albums/*/metadata*',
            'api/metadata-edits*',
        ],
    ],

    'audio_extensions' => [
        'aac',
        'aif',
        'aiff',
        'alac',
        'flac',
        'm4a',
        'mp3',
        'oga',
        'ogg',
        'opus',
        'wav',
        'wma',
    ],

    'artwork' => [
        'disk' => env('ARTWORK_DISK', 'artwork'),
        'thumbnail_width' => (int) env('ARTWORK_THUMBNAIL_WIDTH', 320),
        'thumbnail_height' => (int) env('ARTWORK_THUMBNAIL_HEIGHT', 320),
        'thumbnail_quality' => (int) env('ARTWORK_THUMBNAIL_QUALITY', 82),
        'max_source_bytes' => (int) env('ARTWORK_MAX_SOURCE_BYTES', 25 * 1024 * 1024),
        'max_source_pixels' => (int) env('ARTWORK_MAX_SOURCE_PIXELS', 40_000_000),
    ],
];
