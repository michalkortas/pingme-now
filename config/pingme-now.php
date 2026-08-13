<?php

return [
    'enabled' => env('PINGME_ENABLED', false),
    'url' => env('PINGME_URL'),
    'token' => env('PINGME_TOKEN'),
    'queue' => [
        'enabled' => env('PINGME_QUEUE_ENABLED', true),
        'connection' => env('PINGME_QUEUE_CONNECTION'),
        'name' => env('PINGME_QUEUE', 'default'),
    ],
    'disk_paths' => array_values(array_filter(array_map('trim', explode(',', (string) env('PINGME_DISK_PATHS', ''))))),
    'ssl_url' => env('PINGME_SSL_URL', env('APP_URL')),
    'log_level' => env('PINGME_LOG_LEVEL', 'error'),
    'spool_path' => env('PINGME_SPOOL_PATH', storage_path('framework/pingme')),
    'spool_max_bytes' => (int) env('PINGME_SPOOL_MAX_BYTES', 20 * 1024 * 1024),
    'timeout' => (int) env('PINGME_TIMEOUT', 10),
];
