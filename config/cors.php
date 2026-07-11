<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('CORS_ALLOWED_ORIGINS_LOCAL') ?: 'http://localhost:8000',
        env('CORS_ALLOWED_ORIGINS_DOMAINS') ?: 'https://app.miinventariofacil.com',
        env('APP_URL') ? parse_url(env('APP_URL'), PHP_URL_SCHEME).'://'.parse_url(env('APP_URL'), PHP_URL_HOST) : null,
    ]),

    'allowed_origins_patterns' => array_filter([
        env('APP_ENV') === 'local' ? '/^http:\/\/localhost:\d+$/' : null,
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
