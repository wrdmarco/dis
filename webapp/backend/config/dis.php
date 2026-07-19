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
        'application_estimated_duration_seconds' => (int) env('APPLICATION_UPDATE_ESTIMATED_DURATION_SECONDS', 900),
        'system_estimated_duration_seconds' => (int) env('SYSTEM_UPDATE_ESTIMATED_DURATION_SECONDS', 1500),
    ],
    'system_metrics' => [
        'disk_path' => env('DIS_DATA_PATH', '/opt/dis-data'),
    ],
    'wallboards' => [
        'pairing_ttl_seconds' => (int) env('WALLBOARD_PAIRING_TTL_SECONDS', 300),
        'credential_cookie_days' => (int) env('WALLBOARD_CREDENTIAL_COOKIE_DAYS', 365),
        'rotation_hours' => (int) env('WALLBOARD_SESSION_ROTATION_HOURS', 12),
        'rotation_grace_seconds' => (int) env('WALLBOARD_SESSION_ROTATION_GRACE_SECONDS', 120),
        'touch_interval_seconds' => (int) env('WALLBOARD_SESSION_TOUCH_INTERVAL_SECONDS', 60),
        'ticker_connect_timeout_seconds' => (int) env('WALLBOARD_TICKER_CONNECT_TIMEOUT_SECONDS', 2),
        'ticker_timeout_seconds' => (int) env('WALLBOARD_TICKER_TIMEOUT_SECONDS', 4),
        'ticker_cache_seconds' => (int) env('WALLBOARD_TICKER_CACHE_SECONDS', 300),
        'ticker_failure_cache_seconds' => (int) env('WALLBOARD_TICKER_FAILURE_CACHE_SECONDS', 60),
        'uav_forecast' => [
            'connect_timeout_seconds' => (int) env('WALLBOARD_UAV_FORECAST_CONNECT_TIMEOUT_SECONDS', 2),
            'timeout_seconds' => (int) env('WALLBOARD_UAV_FORECAST_TIMEOUT_SECONDS', 5),
            'cache_seconds' => (int) env('WALLBOARD_UAV_FORECAST_CACHE_SECONDS', 900),
            'last_good_cache_seconds' => (int) env('WALLBOARD_UAV_FORECAST_LAST_GOOD_CACHE_SECONDS', 21600),
            'geocode_cache_seconds' => (int) env('WALLBOARD_UAV_FORECAST_GEOCODE_CACHE_SECONDS', 2592000),
            'weather_stale_seconds' => (int) env('WALLBOARD_UAV_FORECAST_WEATHER_STALE_SECONDS', 1800),
            'kp_stale_seconds' => (int) env('WALLBOARD_UAV_FORECAST_KP_STALE_SECONDS', 14400),
            // Application-owned reference points: one stable sample in every Dutch
            // province. National forecasts never depend on twelve geocoder calls.
            'province_reference_points' => [
                ['label' => 'Drenthe', 'latitude' => 52.9928, 'longitude' => 6.5642],
                ['label' => 'Flevoland', 'latitude' => 52.5185, 'longitude' => 5.4714],
                ['label' => 'Friesland', 'latitude' => 53.2012, 'longitude' => 5.7999],
                ['label' => 'Gelderland', 'latitude' => 51.9851, 'longitude' => 5.8987],
                ['label' => 'Groningen', 'latitude' => 53.2194, 'longitude' => 6.5665],
                ['label' => 'Limburg', 'latitude' => 50.8514, 'longitude' => 5.6910],
                ['label' => 'Noord-Brabant', 'latitude' => 51.6978, 'longitude' => 5.3037],
                ['label' => 'Noord-Holland', 'latitude' => 52.3874, 'longitude' => 4.6462],
                ['label' => 'Overijssel', 'latitude' => 52.5168, 'longitude' => 6.0830],
                ['label' => 'Utrecht', 'latitude' => 52.0907, 'longitude' => 5.1214],
                ['label' => 'Zeeland', 'latitude' => 51.4988, 'longitude' => 3.6100],
                ['label' => 'Zuid-Holland', 'latitude' => 52.0705, 'longitude' => 4.3007],
            ],
            'thresholds' => [
                // Conservatieve operationele defaults; toestel-, missie- en lokale limieten gaan altijd voor.
                'temperature_c' => [
                    'green_min' => (float) env('WALLBOARD_UAV_TEMPERATURE_GREEN_MIN_C', 0),
                    'green_max' => (float) env('WALLBOARD_UAV_TEMPERATURE_GREEN_MAX_C', 35),
                    'orange_min' => (float) env('WALLBOARD_UAV_TEMPERATURE_ORANGE_MIN_C', -10),
                    'orange_max' => (float) env('WALLBOARD_UAV_TEMPERATURE_ORANGE_MAX_C', 45),
                ],
                'dew_point_c' => [
                    'green_spread_min' => (float) env('WALLBOARD_UAV_DEW_POINT_GREEN_SPREAD_MIN_C', 3),
                    'orange_spread_min' => (float) env('WALLBOARD_UAV_DEW_POINT_ORANGE_SPREAD_MIN_C', 1.5),
                ],
                'wind_speed_kmh' => [
                    'green_max' => (float) env('WALLBOARD_UAV_WIND_GREEN_MAX_KMH', 20),
                    'orange_max' => (float) env('WALLBOARD_UAV_WIND_ORANGE_MAX_KMH', 30),
                ],
                'wind_gust_kmh' => [
                    'green_max' => (float) env('WALLBOARD_UAV_GUST_GREEN_MAX_KMH', 30),
                    'orange_max' => (float) env('WALLBOARD_UAV_GUST_ORANGE_MAX_KMH', 45),
                ],
                'precipitation_mm' => [
                    'green_max' => (float) env('WALLBOARD_UAV_PRECIPITATION_GREEN_MAX_MM', 0),
                    'orange_max' => (float) env('WALLBOARD_UAV_PRECIPITATION_ORANGE_MAX_MM', 0.5),
                ],
                'precipitation_probability_pct' => [
                    'green_max' => (float) env('WALLBOARD_UAV_PRECIPITATION_PROBABILITY_GREEN_MAX_PCT', 20),
                    'orange_max' => (float) env('WALLBOARD_UAV_PRECIPITATION_PROBABILITY_ORANGE_MAX_PCT', 50),
                ],
                'cloud_cover_pct' => [
                    'green_max' => (float) env('WALLBOARD_UAV_CLOUD_COVER_GREEN_MAX_PCT', 50),
                    'orange_max' => (float) env('WALLBOARD_UAV_CLOUD_COVER_ORANGE_MAX_PCT', 85),
                ],
                'visibility_m' => [
                    'green_min' => (float) env('WALLBOARD_UAV_VISIBILITY_GREEN_MIN_M', 5000),
                    'orange_min' => (float) env('WALLBOARD_UAV_VISIBILITY_ORANGE_MIN_M', 2000),
                ],
                'kp_index' => [
                    'green_max_exclusive' => (float) env('WALLBOARD_UAV_KP_GREEN_MAX_EXCLUSIVE', 4),
                    'orange_max_exclusive' => (float) env('WALLBOARD_UAV_KP_ORANGE_MAX_EXCLUSIVE', 6),
                ],
            ],
        ],
    ],
    'retention' => [
        'push_logs_days' => (int) env('PUSH_LOG_RETENTION_DAYS', 90),
        'audit_logs_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 3650),
    ],
];
