<?php

return [
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
