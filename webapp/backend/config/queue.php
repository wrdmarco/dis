<?php

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),
    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => true,
        ],
        'wallboard_media' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => 'wallboard-media',
            'retry_after' => 3900,
            'block_for' => 5,
            'after_commit' => true,
        ],
        'knmi' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => 'knmi',
            'retry_after' => max(7500, (int) env('KNMI_QUEUE_RETRY_AFTER', 7500)),
            'block_for' => 5,
            'after_commit' => true,
        ],
        'knmi_realtime' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => 'knmi-realtime',
            'retry_after' => max(900, (int) env('KNMI_REALTIME_QUEUE_RETRY_AFTER', 900)),
            'block_for' => 5,
            'after_commit' => true,
        ],
    ],
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],
];
