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
        'aeret_map_url' => env('AERET_DRONE_MAP_URL', 'https://dronepreflight.nl/'),
        'aeret_api_url' => env('AERET_API_URL'),
        'notam_url' => env('DRONE_NOTAM_URL', 'https://www.lvnl.nl/informatie-voor-luchtvarenden/notam'),
        'weather_provider' => 'Open-Meteo',
    ],
    'geocoding' => [
        'enabled' => filter_var(env('GEOCODING_ENABLED', true), FILTER_VALIDATE_BOOL),
        'provider' => env('GEOCODING_PROVIDER', 'nominatim'),
        'nominatim_url' => env('GEOCODING_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search'),
        'user_agent' => env('GEOCODING_USER_AGENT'),
        'country_codes' => env('GEOCODING_COUNTRY_CODES', 'nl,be,de'),
    ],
    'updates' => [
        'android_application_id' => env('ANDROID_APPLICATION_ID', 'nl.wrdmarco.dis'),
    ],
    'retention' => [
        'push_logs_days' => (int) env('PUSH_LOG_RETENTION_DAYS', 90),
        'audit_logs_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 3650),
    ],
];
