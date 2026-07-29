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
        'push' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => 'push',
            // Must remain longer than the dedicated worker timeout so an
            // in-flight provider request is never delivered concurrently.
            'retry_after' => max(240, (int) env('PUSH_QUEUE_RETRY_AFTER', 240)),
            'block_for' => 5,
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
    ],
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],
];
