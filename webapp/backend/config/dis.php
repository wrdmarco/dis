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
    'deployment_location' => [
        'enabled' => filter_var(env('DEPLOYMENT_LOCATION_ENRICHMENT_ENABLED', true), FILTER_VALIDATE_BOOL),
        'wfs_url' => env('DEPLOYMENT_PROVINCE_WFS_URL', 'https://service.pdok.nl/kadaster/brk-bestuurlijke-gebieden/wfs/v1_0'),
        'country_url' => env('DEPLOYMENT_COUNTRY_GISCO_URL', 'https://gisco-services.ec.europa.eu/id/country'),
        'connect_timeout_seconds' => (int) env('DEPLOYMENT_LOCATION_CONNECT_TIMEOUT_SECONDS', 2),
        'timeout_seconds' => (int) env('DEPLOYMENT_LOCATION_TIMEOUT_SECONDS', 5),
        'backfill_batch' => max(2, min(3, (int) env('DEPLOYMENT_LOCATION_BACKFILL_BATCH', 3))),
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
    'system_logs' => [
        // Keep the browser log viewer on the canonical Laravel log directory.
        // This path may itself be the deployment-managed storage symlink.
        'directory' => storage_path('logs'),
    ],
    'knmi_radar' => [
        // KNMI documents this anonymous ADAGUC WMS endpoint for browser and
        // server use without an API key. Dataset, layer, style and map bounds
        // stay fixed so this cannot become a generic outbound request proxy.
        'endpoint' => 'https://anonymous.api.dataplatform.knmi.nl/wms/adaguc-server',
        'observation_dataset' => 'nl_rdr_data_rtcor_5m',
        'observation_layer' => 'precipitation_real_time',
        'forecast_dataset' => 'radar_forecast_2.0',
        'forecast_layer' => 'precipitation_nowcast',
        'style' => 'rainrate-blue-to-purple/shaded',
        'srs' => 'EPSG:4326',
        'bbox' => [1.0, 49.0, 10.0, 55.0],
        'frame_width' => 1200,
        'frame_height' => 800,
        'history_minutes' => 60,
        'forecast_minutes' => 120,
        'interval_minutes' => 5,
        'connect_timeout_seconds' => 5,
        'capabilities_timeout_seconds' => 15,
        'frame_timeout_seconds' => 20,
        'maximum_capabilities_bytes' => 262_144,
        'maximum_frame_bytes' => 1_048_576,
        'timeline_cache_seconds' => 240,
        // Anonymous WMS access is limited to one request per second per IP.
        // Every PHP process shares this Redis-backed gate; a small positive
        // jitter prevents request starts from accumulating on the boundary.
        'upstream_throttle_wait_seconds' => 5,
        'upstream_minimum_interval_milliseconds' => 1050,
        'upstream_jitter_milliseconds' => 50,
        // HMAC frame tokens, a 1 MiB payload ceiling and this two-hour TTL
        // bound Redis use while retaining the full one-hour history plus the
        // complete one-hour stale-fallback window.
        'frame_cache_seconds' => 7200,
        'maximum_age_seconds' => 1200,
        'maximum_fallback_age_seconds' => 3600,
    ],
    'eumetsat_lightning' => [
        // EUMETView exposes this WMS without an account, token or API key. Keep
        // the remote contract fixed so a configuration change cannot turn the
        // live frame fetcher into a generic outbound request primitive.
        'endpoint' => 'https://view.eumetsat.int/geoserver/wms',
        'layer' => 'mtg_fd:li_afa',
        'style' => 'mtg_li_afa',
        'crs' => 'CRS:84',
        'bbox' => [1.0, 49.0, 10.0, 55.0],
        'frame_width' => 960,
        'frame_height' => 640,
        'frame_count' => 7,
        'interval_minutes' => 5,
        'connect_timeout_seconds' => 5,
        'capabilities_timeout_seconds' => 15,
        'frame_timeout_seconds' => 20,
        'maximum_capabilities_bytes' => 1_048_576,
        'maximum_frame_bytes' => 262_144,
        // LI AFA is published with normal source latency. Thirty minutes keeps
        // current frames usable without presenting a prolonged outage as live;
        // older validated Redis-cached frames are exposed only as an explicitly
        // stale, time-bounded fallback.
        'maximum_age_seconds' => 1800,
        'source_name' => 'EUMETSAT MTG Lightning Imager',
        'source_url' => 'https://view.eumetsat.int/',
        'license_name' => 'CC BY 4.0',
        'license_url' => 'https://user.eumetsat.int/resources/user-guides/data-registration-and-licensing',
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
            'dmi_model_cache_seconds' => (int) env('WALLBOARD_UAV_FORECAST_DMI_MODEL_CACHE_SECONDS', 600),
            'dmi_model_stale_seconds' => (int) env('DMI_MODEL_STALE_SECONDS', 21600),
            'dmi_valid_window_seconds' => (int) env('DMI_VALID_WINDOW_SECONDS', 5400),
            'dmi_lock_seconds' => (int) env('DMI_LOCK_SECONDS', 20),
            'dmi_retry_delay_ms' => (int) env('DMI_RETRY_DELAY_MS', 250),
            'bright_sky_connect_timeout_seconds' => (int) env('BRIGHT_SKY_CONNECT_TIMEOUT_SECONDS', 2),
            'bright_sky_timeout_seconds' => (int) env('BRIGHT_SKY_TIMEOUT_SECONDS', 5),
            'bright_sky_lock_seconds' => (int) env('BRIGHT_SKY_LOCK_SECONDS', 20),
            'bright_sky_retry_delay_ms' => (int) env('BRIGHT_SKY_RETRY_DELAY_MS', 250),
            'dwd_mosmix_connect_timeout_seconds' => (int) env('DWD_MOSMIX_CONNECT_TIMEOUT_SECONDS', 2),
            'dwd_mosmix_timeout_seconds' => (int) env('DWD_MOSMIX_TIMEOUT_SECONDS', 5),
            'dwd_mosmix_lock_seconds' => (int) env('DWD_MOSMIX_LOCK_SECONDS', 20),
            'dwd_mosmix_retry_delay_ms' => (int) env('DWD_MOSMIX_RETRY_DELAY_MS', 250),
            'dwd_mosmix_cache_seconds' => (int) env('DWD_MOSMIX_CACHE_SECONDS', 900),
            'dwd_mosmix_last_good_cache_seconds' => (int) env('DWD_MOSMIX_LAST_GOOD_CACHE_SECONDS', 21600),
            'dwd_mosmix_model_stale_seconds' => (int) env('DWD_MOSMIX_MODEL_STALE_SECONDS', 43200),
            // Official IGS daily merged GPS/Galileo broadcast ephemerides via
            // BKG. The service parses gzip/RINEX in memory and shares only
            // validated source/calculation arrays through the application cache.
            'gnss_connect_timeout_seconds' => (int) env('GNSS_CONNECT_TIMEOUT_SECONDS', 3),
            'gnss_timeout_seconds' => (int) env('GNSS_TIMEOUT_SECONDS', 12),
            'gnss_lock_seconds' => (int) env('GNSS_LOCK_SECONDS', 45),
            'gnss_lock_wait_milliseconds' => (int) env('GNSS_LOCK_WAIT_MILLISECONDS', 5000),
            'gnss_source_cache_seconds' => (int) env('GNSS_SOURCE_CACHE_SECONDS', 900),
            'gnss_last_good_cache_seconds' => (int) env('GNSS_LAST_GOOD_CACHE_SECONDS', 21600),
            'gnss_calculation_cache_seconds' => (int) env('GNSS_CALCULATION_CACHE_SECONDS', 300),
            'gnss_failure_cache_seconds' => (int) env('GNSS_FAILURE_CACHE_SECONDS', 30),
            'gnss_ephemeris_max_age_seconds' => (int) env('GNSS_EPHEMERIS_MAX_AGE_SECONDS', 14400),
            'gnss_ephemeris_future_tolerance_seconds' => (int) env('GNSS_EPHEMERIS_FUTURE_TOLERANCE_SECONDS', 1800),
            'gnss_utc_offset_seconds' => (int) env('GNSS_UTC_OFFSET_SECONDS', 18),
            'gnss_elevation_mask_degrees' => (float) env('GNSS_ELEVATION_MASK_DEGREES', 10),
            'gnss_min_ephemerides_per_constellation' => (int) env('GNSS_MIN_EPHEMERIDES_PER_CONSTELLATION', 12),
            'knmi_edr_api_key' => env('KNMI_EDR_API_KEY'),
            'cloud_base_station_cache_seconds' => (int) env('WALLBOARD_UAV_CLOUD_BASE_STATION_CACHE_SECONDS', 86400),
            'cloud_base_stale_seconds' => (int) env('WALLBOARD_UAV_CLOUD_BASE_STALE_SECONDS', 1800),
            'cloud_base_max_distance_km' => (float) env('WALLBOARD_UAV_CLOUD_BASE_MAX_DISTANCE_KM', 30),
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
                'gnss_pdop' => [
                    'adequate_max' => (float) env('WALLBOARD_UAV_GNSS_PDOP_ADEQUATE_MAX', 6),
                ],
            ],
        ],
    ],
    'queue_monitor' => [
        'refresh_after_seconds' => 5,
        'recent_hours' => 24,
        // Manual queue actions inspect only this bounded number of Redis
        // entries. A task outside the window remains untouched and can be
        // handled normally by the worker.
        'manual_action_scan_limit' => max(
            1,
            min(5000, (int) env('PUSH_QUEUE_MANUAL_ACTION_SCAN_LIMIT', 1000)),
        ),
        'queues' => [
            // This is installed capacity, not a live worker-status claim.
            'push' => [
                'configured_parallelism' => 4,
                'worker_timeout_seconds' => 180,
                'max_attempts' => 4,
                'stale_active_after_seconds' => 7200,
            ],
        ],
    ],
    'retention' => [
        'push_logs_days' => (int) env('PUSH_LOG_RETENTION_DAYS', 90),
        'push_queue_work_items_days' => max(1, (int) env('PUSH_QUEUE_WORK_ITEM_RETENTION_DAYS', 7)),
        'audit_logs_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 3650),
    ],
];
