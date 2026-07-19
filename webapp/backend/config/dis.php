<?php

return [
    'teams' => [
        'base_team_code' => 'OCP',
        'tui_team_code' => 'TUI',
    ],
    'push' => [
        'availability_requires_push' => true,
        'fcm_project_id' => env('FCM_PROJECT_ID'),
    ],
    'location' => [
        'default_retention_days' => (int) env('LOCATION_RETENTION_DAYS', 30),
    ],
    'drone_flight' => [
        'aeret_map_url' => env('AERET_DRONE_MAP_URL', 'https://aeret.kaartviewer.nl/?@dpf_basic'),
        'aeret_api_url' => env('AERET_API_URL'),
        'weather_provider' => 'Open-Meteo',
    ],
    'geocoding' => [
        'enabled' => filter_var(env('GEOCODING_ENABLED', true), FILTER_VALIDATE_BOOL),
        'provider' => env('GEOCODING_PROVIDER', 'nominatim'),
        'nominatim_url' => env('GEOCODING_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search'),
        'user_agent' => env('GEOCODING_USER_AGENT'),
        'country_codes' => env('GEOCODING_COUNTRY_CODES', 'nl,be,de'),
    ],
    'dispatch' => [
        'eta_ring_minutes' => (int) env('DISPATCH_ETA_RING_MINUTES', 15),
        'estimated_eta_speed_kmh' => (float) env('DISPATCH_ESTIMATED_ETA_SPEED_KMH', 60),
    ],
    'routing' => [
        'enabled' => filter_var(env('ROUTING_ENABLED', false), FILTER_VALIDATE_BOOL),
        'provider' => env('ROUTING_PROVIDER', 'osrm'),
        'admin_sources' => [
            [
                'id' => 'netherlands',
                'label' => 'Nederland',
                'latest_url' => 'https://download.geofabrik.de/europe/netherlands-latest.osm.pbf',
            ],
            [
                'id' => 'belgium',
                'label' => 'België',
                'latest_url' => 'https://download.geofabrik.de/europe/belgium-latest.osm.pbf',
            ],
        ],
        'admin_status_path' => env('OSRM_ADMIN_STATUS_PATH', '/var/log/dis/osrm-status.json'),
        'admin_state_root' => env('OSRM_ADMIN_STATE_ROOT', rtrim((string) env('DIS_DATA_PATH', '/opt/dis-data'), '/').'/osrm-admin'),
        'admin_health_coordinate' => [
            'longitude' => 5.1214,
            'latitude' => 52.0907,
        ],
        'cache_ttl_seconds' => (int) env('ROUTING_CACHE_TTL_SECONDS', 900),
        'failure_cache_ttl_seconds' => (int) env('ROUTING_FAILURE_CACHE_TTL_SECONDS', 15),
        'fallback_speed_kmh' => (float) env('ROUTING_FALLBACK_SPEED_KMH', env('DISPATCH_ESTIMATED_ETA_SPEED_KMH', 60)),
        'osrm' => [
            'base_url' => env('OSRM_BASE_URL'),
            'allowed_hosts' => env('OSRM_ALLOWED_HOSTS', '127.0.0.1,localhost,::1'),
            'profile' => env('OSRM_PROFILE', 'driving'),
            'connect_timeout_seconds' => (int) env('OSRM_CONNECT_TIMEOUT_SECONDS', 1),
            'timeout_seconds' => (int) env('OSRM_TIMEOUT_SECONDS', 3),
            'batch_size' => (int) env('OSRM_BATCH_SIZE', 50),
            'geometry_max_routes' => (int) env('OSRM_GEOMETRY_MAX_ROUTES', 25),
            'geometry_concurrency' => (int) env('OSRM_GEOMETRY_CONCURRENCY', 10),
        ],
    ],
    'updates' => [
        'android_application_id' => env('ANDROID_APPLICATION_ID', 'nl.wrdmarco.dis'),
    ],
    'system_metrics' => [
        'disk_path' => env('DIS_DATA_PATH', '/opt/dis-data'),
    ],
    'wallboards' => [
        'pairing_ttl_seconds' => (int) env('WALLBOARD_PAIRING_TTL_SECONDS', 300),
        'session_idle_days' => (int) env('WALLBOARD_SESSION_IDLE_DAYS', 30),
        'session_absolute_days' => (int) env('WALLBOARD_SESSION_ABSOLUTE_DAYS', 365),
        'rotation_hours' => (int) env('WALLBOARD_SESSION_ROTATION_HOURS', 12),
        'rotation_grace_seconds' => (int) env('WALLBOARD_SESSION_ROTATION_GRACE_SECONDS', 120),
        'touch_interval_seconds' => (int) env('WALLBOARD_SESSION_TOUCH_INTERVAL_SECONDS', 60),
        'ticker_connect_timeout_seconds' => (int) env('WALLBOARD_TICKER_CONNECT_TIMEOUT_SECONDS', 2),
        'ticker_timeout_seconds' => (int) env('WALLBOARD_TICKER_TIMEOUT_SECONDS', 4),
        'ticker_cache_seconds' => (int) env('WALLBOARD_TICKER_CACHE_SECONDS', 300),
        'ticker_failure_cache_seconds' => (int) env('WALLBOARD_TICKER_FAILURE_CACHE_SECONDS', 60),
    ],
    'retention' => [
        'push_logs_days' => (int) env('PUSH_LOG_RETENTION_DAYS', 90),
        'audit_logs_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 3650),
    ],
];
