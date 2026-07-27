<?php

$originFromUrl = static function (?string $url): ?string {
    if (! $url) {
        return null;
    }

    $parts = parse_url($url);
    if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
};

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('CORS_ALLOWED_ORIGINS_LOCAL') ?: 'http://localhost:8000',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        env('CORS_ALLOWED_ORIGINS_DOMAINS') ?: 'https://app.miinventariofacil.com',
        $originFromUrl(env('APP_URL')),
    ]),

    'allowed_origins_patterns' => array_filter([
        env('APP_ENV') === 'local' ? '/^http:\/\/(?:localhost|127\.0\.0\.1):\d+$/' : null,
    ]),

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'Link',
        'X-Request-Id',
    ],

    'max_age' => 86400,

    'supports_credentials' => true,
];
