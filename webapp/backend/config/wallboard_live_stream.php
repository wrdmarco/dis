<?php

$dataPath = rtrim((string) env('DIS_DATA_PATH', '/opt/dis-data'), '/\\');

return [
    'enabled' => (bool) env('WALLBOARD_LIVE_STREAM_ENABLED', false),
    'public_host' => (string) env('WALLBOARD_LIVE_STREAM_PUBLIC_HOST', ''),
    'rtmps_bind_address' => (string) env('WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS', '0.0.0.0'),
    'rtmps_port' => (int) env('WALLBOARD_LIVE_STREAM_RTMPS_PORT', 1936),
    'stream_key' => (string) env('WALLBOARD_LIVE_STREAM_STREAM_KEY', ''),
    'tls_certificate_path' => (string) env('WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH', ''),
    'tls_private_key_path' => (string) env('WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH', ''),
    'runtime_directory' => '/run/dis-wallboard-live',
    'output_directory' => '/run/dis-wallboard-live/hls',
    'segment_duration_seconds' => 2,
    'segment_list_size' => 6,
    'manifest_stale_seconds' => (int) env('WALLBOARD_LIVE_STREAM_STALE_SECONDS', 12),
    'max_manifest_bytes' => 64 * 1024,
    'max_segment_bytes' => 6 * 1024 * 1024,
    'managed_env_path' => $dataPath.DIRECTORY_SEPARATOR.'.env',
    'key_request_directory' => $dataPath.DIRECTORY_SEPARATOR.'wallboard-live-key-requests',
];
