<?php

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('SONOTHEQUE_ALLOWED_ORIGINS', '')),
)));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'X-Sonotheque-Admin-Token',
    ],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
