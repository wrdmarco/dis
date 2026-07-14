<?php

$normalizeOrigin = static function (string $origin): ?string {
    $parts = parse_url(trim($origin));
    if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    $scheme = mb_strtolower((string) $parts['scheme']);
    if (! in_array($scheme, ['http', 'https'], true)
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
        || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))) {
        return null;
    }

    $origin = $scheme.'://'.mb_strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
        $origin .= ':'.(int) $parts['port'];
    }

    return $origin;
};

$configuredOrigins = explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('APP_URL', 'http://localhost')));
$allowedOrigins = array_values(array_unique(array_filter(array_map($normalizeOrigin, $configuredOrigins))));

return [
    'paths' => ['api/*', 'broadcasting/auth'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-CSRF-TOKEN',
        'X-DIS-Developer-Key',
        'X-Requested-With',
        'X-Request-ID',
        'X-XSRF-TOKEN',
    ],
    'exposed_headers' => ['Retry-After', 'X-Request-ID'],
    'max_age' => 600,
    'supports_credentials' => true,
];
