<?php

$trustedHosts = array_values(array_filter(array_map(
    static fn (string $host): string => trim($host),
    explode(',', (string) env('MUSIC_LIBRARY_TRUSTED_HOSTS', 'localhost,127.0.0.1,::1')),
)));

return [
    'scan_memory_limit' => env('SCAN_MEMORY_LIMIT', '1024M'),
    'scan_stale_after_minutes' => (int) env('SCAN_STALE_AFTER_MINUTES', 15),
    'counted_play_threshold_seconds' => (int) env('PLAY_STATISTICS_COUNTED_PLAY_THRESHOLD_SECONDS', 15),
    'play_statistics_sync_delay_seconds' => (int) env('PLAY_STATISTICS_SYNC_DELAY_SECONDS', 30),

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
