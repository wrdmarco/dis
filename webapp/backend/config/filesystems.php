<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => true,
        ],
        'backups' => [
            'driver' => 'local',
            'root' => env('BACKUP_DISK_PATH', '/opt/dis/storage/backups'),
            'throw' => true,
        ],
    ],
];

