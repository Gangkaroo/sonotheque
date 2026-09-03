<?php

$trustedHosts = array_values(array_filter(array_map(
    static fn (string $host): string => trim($host),
    explode(',', (string) env('SONOTHEQUE_TRUSTED_HOSTS', 'localhost,127.0.0.1,::1')),
)));
$enrichmentCaBundle = env('ENRICHMENT_CA_BUNDLE') ?: env('LASTFM_CA_BUNDLE');

return [
    'scan_memory_limit' => env('SCAN_MEMORY_LIMIT', '1024M'),
    'scan_manifest_directory' => env(
        'SCAN_MANIFEST_DIRECTORY',
        storage_path('app/private/scan-manifests'),
    ),
    'scan_stale_after_minutes' => (int) env('SCAN_STALE_AFTER_MINUTES', 15),
    'library_activity_retention_days' => (int) env('LIBRARY_ACTIVITY_RETENTION_DAYS', 90),
    'counted_play_minimum_track_seconds' => (int) env('PLAY_STATISTICS_MINIMUM_TRACK_SECONDS', 30),
    'counted_play_maximum_threshold_seconds' => (int) env('PLAY_STATISTICS_MAXIMUM_THRESHOLD_SECONDS', 240),
    'play_statistics_sync_delay_seconds' => (int) env('PLAY_STATISTICS_SYNC_DELAY_SECONDS', 30),
    'audio_stream_open_ended_range_bytes' => (int) env('AUDIO_STREAM_OPEN_ENDED_RANGE_BYTES', 2 * 1024 * 1024),
    'audio_stream_activity_grace_seconds' => (int) env('AUDIO_STREAM_ACTIVITY_GRACE_SECONDS', 300),

    'metadata_probe' => [
        'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
        'timeout_seconds' => (int) env('FFPROBE_TIMEOUT_SECONDS', 15),
    ],

    'audio_fingerprint' => [
        'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'timeout_seconds' => (int) env('AUDIO_FINGERPRINT_TIMEOUT_SECONDS', 60),
    ],

    'audio_intelligence' => [
        'driver' => env('AUDIO_INTELLIGENCE_DRIVER', 'none'),
        'python_binary' => env('AUDIO_INTELLIGENCE_PYTHON_BINARY', 'python'),
        'worker_script' => env(
            'AUDIO_INTELLIGENCE_WORKER_SCRIPT',
            base_path('../audio-intelligence/worker.py'),
        ),
        'model_path' => env('AUDIO_INTELLIGENCE_MODEL_PATH', ''),
        'timeout_seconds' => (int) env('AUDIO_INTELLIGENCE_TIMEOUT_SECONDS', 14400),
        'docker_image' => env(
            'AUDIO_INTELLIGENCE_DOCKER_IMAGE',
            'sonotheque-audio-intelligence:analysis',
        ),
        'benchmark_cpu_image' => env(
            'AUDIO_INTELLIGENCE_BENCHMARK_CPU_IMAGE',
            'sonotheque-audio-intelligence:analysis',
        ),
        'benchmark_cuda_image' => env(
            'AUDIO_INTELLIGENCE_BENCHMARK_CUDA_IMAGE',
            'sonotheque-audio-intelligence:cuda',
        ),
        'benchmark_sample_size' => (int) env(
            'AUDIO_INTELLIGENCE_BENCHMARK_SAMPLE_SIZE',
            15,
        ),
        'accelerator' => env('AUDIO_INTELLIGENCE_ACCELERATOR', 'cpu'),
        'persistent' => (bool) env('AUDIO_INTELLIGENCE_PERSISTENT', false),
        'persistent_container_name' => env(
            'AUDIO_INTELLIGENCE_CONTAINER_NAME',
            'sonotheque-audio-analyzer',
        ),
        'persistent_startup_timeout_seconds' => (int) env(
            'AUDIO_INTELLIGENCE_STARTUP_TIMEOUT_SECONDS',
            90,
        ),
        'mount_source_container' => env('AUDIO_INTELLIGENCE_MOUNT_SOURCE_CONTAINER') ?: null,
        'health_via_queue' => (bool) env('AUDIO_INTELLIGENCE_HEALTH_VIA_QUEUE', false),
        'health_queue_timeout_seconds' => (int) env(
            'AUDIO_INTELLIGENCE_HEALTH_QUEUE_TIMEOUT_SECONDS',
            120,
        ),
        'cpu_limit' => (float) env('AUDIO_INTELLIGENCE_CPU_LIMIT', 2),
        'memory_limit' => env('AUDIO_INTELLIGENCE_MEMORY_LIMIT', '4g'),
        'preparation_workers' => (int) env('AUDIO_INTELLIGENCE_PREPARATION_WORKERS', 2),
        'chunk_size' => (int) env('AUDIO_INTELLIGENCE_CHUNK_SIZE', 5),
        'preparation_chunk_size' => (int) env('AUDIO_INTELLIGENCE_PREPARATION_CHUNK_SIZE', 10),
        'resume_stale_minutes' => (int) env('AUDIO_INTELLIGENCE_RESUME_STALE_MINUTES', 10),
    ],

    'collection_assistant' => [
        'provider' => 'ollama',
        'ollama_url' => env('COLLECTION_ASSISTANT_OLLAMA_URL', 'http://127.0.0.1:11434'),
        'timeout_seconds' => (int) env('COLLECTION_ASSISTANT_TIMEOUT_SECONDS', 10),
        'test_timeout_seconds' => (int) env('COLLECTION_ASSISTANT_TEST_TIMEOUT_SECONDS', 120),
        'keep_alive' => env('COLLECTION_ASSISTANT_KEEP_ALIVE', '15m'),
        'context_window' => (int) env('COLLECTION_ASSISTANT_CONTEXT_WINDOW', 4096),
        'max_answer_tokens' => (int) env('COLLECTION_ASSISTANT_MAX_ANSWER_TOKENS', 256),
        'recommended_model' => env('COLLECTION_ASSISTANT_RECOMMENDED_MODEL', 'qwen3:4b'),
    ],

    'system_health' => [
        'backup_status_path' => storage_path('app/system-backups/latest.json'),
        'scheduler_heartbeat_key' => 'sonotheque:system-health:scheduler-heartbeat',
        'scheduler_stale_seconds' => (int) env('SYSTEM_HEALTH_SCHEDULER_STALE_SECONDS', 180),
        'worker_heartbeat_interval_seconds' => (int) env('SYSTEM_HEALTH_WORKER_HEARTBEAT_INTERVAL_SECONDS', 10),
        'worker_stale_seconds' => (int) env('SYSTEM_HEALTH_WORKER_STALE_SECONDS', 45),
        'worker_busy_stale_seconds' => (int) env('SYSTEM_HEALTH_WORKER_BUSY_STALE_SECONDS', 300),
    ],

    'system_backups' => [
        'operation_path' => storage_path('app/system-backups/operations'),
        'safety_path' => storage_path('app/system-backups/safety'),
        'use_docker' => filter_var(
            env('SYSTEM_BACKUP_USE_DOCKER', PHP_OS_FAMILY === 'Windows'),
            FILTER_VALIDATE_BOOL,
        ),
        'postgres_container' => env('SYSTEM_BACKUP_POSTGRES_CONTAINER', 'sonotheque-postgres'),
        'pg_dump_path' => env('SYSTEM_BACKUP_PG_DUMP_PATH', ''),
        'pg_restore_path' => env('SYSTEM_BACKUP_PG_RESTORE_PATH', ''),
    ],

    'lastfm' => [
        'api_url' => env('LASTFM_API_URL', 'https://ws.audioscrobbler.com/2.0/'),
        'auth_url' => env('LASTFM_AUTH_URL', 'https://www.last.fm/api/auth/'),
        'timeout_seconds' => (int) env('LASTFM_TIMEOUT_SECONDS', 10),
        'proxy' => env('LASTFM_PROXY', ''),
        'ca_bundle' => env('LASTFM_CA_BUNDLE') ?: $enrichmentCaBundle,
    ],

    'discogs' => [
        'api_url' => env('DISCOGS_API_URL', 'https://api.discogs.com'),
        'web_url' => env('DISCOGS_WEB_URL', 'https://www.discogs.com'),
        'user_agent' => env(
            'DISCOGS_USER_AGENT',
            'Sonotheque/0.1 (https://github.com/Gangkaroo/sonotheque)',
        ),
        'timeout_seconds' => (int) env('DISCOGS_TIMEOUT_SECONDS', 20),
        'proxy' => env('DISCOGS_PROXY', ''),
        'ca_bundle' => env('DISCOGS_CA_BUNDLE') ?: $enrichmentCaBundle,
    ],

    'enrichment' => [
        'user_agent' => env('ENRICHMENT_USER_AGENT', 'Sonotheque/0.1 (local music library application)'),
        'ready_cache_days' => (int) env('ENRICHMENT_READY_CACHE_DAYS', 30),
        'stale_cache_days' => (int) env('ENRICHMENT_STALE_CACHE_DAYS', 7),
        'not_found_cache_hours' => (int) env('ENRICHMENT_NOT_FOUND_CACHE_HOURS', 24),
        'error_retry_minutes' => (int) env('ENRICHMENT_ERROR_RETRY_MINUTES', 15),
        'max_error_retry_minutes' => (int) env('ENRICHMENT_MAX_ERROR_RETRY_MINUTES', 360),
        'lock_seconds' => (int) env('ENRICHMENT_LOCK_SECONDS', 30),
        'lock_wait_seconds' => (int) env('ENRICHMENT_LOCK_WAIT_SECONDS', 12),
        'image_disk' => env('ENRICHMENT_IMAGE_DISK', 'local'),
        'image_path' => env('ENRICHMENT_IMAGE_PATH', 'enrichment-images'),
        'image_max_bytes' => (int) env('ENRICHMENT_IMAGE_MAX_BYTES', 8 * 1024 * 1024),
        'image_max_pixels' => (int) env('ENRICHMENT_IMAGE_MAX_PIXELS', 40_000_000),
        'providers' => [
            'lastfm' => [
                'max_requests_per_minute' => (int) env('LASTFM_ENRICHMENT_REQUESTS_PER_MINUTE', 30),
            ],
            'lrclib' => [
                'max_requests_per_minute' => (int) env('LRCLIB_REQUESTS_PER_MINUTE', 60),
            ],
            'musicbrainz' => [
                'max_requests_per_minute' => (int) env('MUSICBRAINZ_REQUESTS_PER_MINUTE', 40),
                'minimum_interval_ms' => (int) env('MUSICBRAINZ_MINIMUM_INTERVAL_MS', 1500),
                'cooldown_seconds' => (int) env('MUSICBRAINZ_COOLDOWN_SECONDS', 60),
            ],
            'wikimedia' => [
                'max_requests_per_minute' => (int) env('WIKIMEDIA_REQUESTS_PER_MINUTE', 30),
            ],
        ],
        'lrclib' => [
            'api_url' => env('LRCLIB_API_URL', 'https://lrclib.net/api'),
            'timeout_seconds' => (int) env('LRCLIB_TIMEOUT_SECONDS', 20),
            'proxy' => env('LRCLIB_PROXY', ''),
            'ca_bundle' => env('LRCLIB_CA_BUNDLE') ?: $enrichmentCaBundle,
        ],
        'musicbrainz' => [
            'api_url' => env('MUSICBRAINZ_API_URL', 'https://musicbrainz.org/ws/2'),
            'web_url' => env('MUSICBRAINZ_WEB_URL', 'https://musicbrainz.org'),
            'user_agent' => env(
                'MUSICBRAINZ_USER_AGENT',
                'Sonotheque/0.1 (https://github.com/Gangkaroo/sonotheque)',
            ),
            'timeout_seconds' => (int) env('MUSICBRAINZ_TIMEOUT_SECONDS', 20),
            'proxy' => env('MUSICBRAINZ_PROXY', ''),
            'ca_bundle' => env('MUSICBRAINZ_CA_BUNDLE') ?: $enrichmentCaBundle,
            'minimum_match_score' => (int) env('MUSICBRAINZ_MINIMUM_MATCH_SCORE', 95),
            'ambiguity_score_gap' => (int) env('MUSICBRAINZ_AMBIGUITY_SCORE_GAP', 10),
        ],
        'wikimedia' => [
            'wikidata_query_url' => env('WIKIDATA_QUERY_URL', 'https://query.wikidata.org/sparql'),
            'commons_api_url' => env('WIKIMEDIA_COMMONS_API_URL', 'https://commons.wikimedia.org/w/api.php'),
            'user_agent' => env(
                'WIKIMEDIA_USER_AGENT',
                env('MUSICBRAINZ_USER_AGENT', 'Sonotheque/0.1 (https://github.com/Gangkaroo/sonotheque)'),
            ),
            'timeout_seconds' => (int) env('WIKIMEDIA_TIMEOUT_SECONDS', 20),
            'proxy' => env('WIKIMEDIA_PROXY', ''),
            'ca_bundle' => env('WIKIMEDIA_CA_BUNDLE') ?: $enrichmentCaBundle,
        ],
    ],

    'metadata_backups' => [
        'default_path' => env('METADATA_BACKUP_PATH', storage_path('app/metadata-backups')),
        'default_retention_days' => (int) env('METADATA_BACKUP_RETENTION_DAYS', 30),
    ],

    'lan' => [
        'enabled' => (bool) env('SONOTHEQUE_LAN_ENABLED', false),
        'local_proxy_enabled' => (bool) env('SONOTHEQUE_LOCAL_PROXY_ENABLED', false),
        'admin_token' => env('SONOTHEQUE_ADMIN_TOKEN'),
        'trusted_hosts' => array_map(
            static fn (string $host): string => '^'.preg_quote($host, '/').'$',
            $trustedHosts,
        ),
        'protected_paths' => [
            'api/folders*',
            'api/library_roots*',
            'api/library-activity*',
            'api/scan_runs*',
            'api/settings*',
            'api/trash*',
            'api/tracks/*/metadata*',
            'api/albums/*/metadata*',
            'api/albums/*/record-label-suggestions*',
            'api/albums/*/personal-metadata',
            'api/albums/*/personal-notes',
            'api/albums/*/discogs*',
            'api/albums/*/owned-copies*',
            'api/albums/*/musician-credits*',
            'api/metadata-edits*',
            'api/musician-reviews*',
            'api/enrichment/albums/*/musicians/release',
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
