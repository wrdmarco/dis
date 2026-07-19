<?php

return [
    'disk' => env('WALLBOARD_MEDIA_DISK', 'local'),
    'root' => 'wallboard-media',
    'max_upload_kilobytes' => (int) env('WALLBOARD_MEDIA_MAX_UPLOAD_KB', 15 * 1024),
    'max_video_upload_kilobytes' => (int) env('WALLBOARD_MEDIA_MAX_VIDEO_UPLOAD_KB', 250 * 1024),
    'max_video_duration_seconds' => (int) env('WALLBOARD_MEDIA_MAX_VIDEO_DURATION_SECONDS', 6 * 60 * 60),
    'max_total_bytes' => (int) env('WALLBOARD_MEDIA_MAX_TOTAL_BYTES', 5 * 1024 * 1024 * 1024),
    'minimum_free_bytes' => (int) env('WALLBOARD_MEDIA_MINIMUM_FREE_BYTES', 1024 * 1024 * 1024),
    'max_assets' => (int) env('WALLBOARD_MEDIA_MAX_ASSETS', 5000),
    'max_source_pixels' => (int) env('WALLBOARD_MEDIA_MAX_SOURCE_PIXELS', 16_000_000),
    'max_output_edge_pixels' => (int) env('WALLBOARD_MEDIA_MAX_OUTPUT_EDGE_PIXELS', 3840),
    'webp_quality' => (int) env('WALLBOARD_MEDIA_WEBP_QUALITY', 88),
    'thumbnail_edge_pixels' => (int) env('WALLBOARD_MEDIA_THUMBNAIL_EDGE_PIXELS', 640),
    'quota_lock_seconds' => (int) env('WALLBOARD_MEDIA_QUOTA_LOCK_SECONDS', 30),
    'quota_wait_seconds' => (int) env('WALLBOARD_MEDIA_QUOTA_WAIT_SECONDS', 5),
    'orphan_grace_seconds' => (int) env('WALLBOARD_MEDIA_ORPHAN_GRACE_SECONDS', 24 * 60 * 60),
];
