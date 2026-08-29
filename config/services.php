<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sync' => [
        'cloud_url' => env('SYNC_CLOUD_URL'),
        'token' => env('SYNC_CLOUD_TOKEN'),
        // Base pública de la NUBE para construir cloud_url de imágenes.
        // En la nube coincide con APP_URL; en los nodos locales debe apuntar a
        // la nube (p.ej. https://app.miinventariofacil.com) para que la imagen
        // se pueda descargar allí.
        // Fallback defensivo: si no hay SYNC_PUBLIC_BASE explícito, derivar la
        // base desde SYNC_CLOUD_URL quitando el sufijo /api (o /api/).
        'public_base' => env('SYNC_PUBLIC_BASE') ?: preg_replace('#/api/?$#', '', (string) env('SYNC_CLOUD_URL', '')),
        // Bundle de CA para clientes HTTP (Guzzle) contra la nube. Si el
        // proceso PHP arranca sin PHP_INI_SCAN_DIR, curl.cainfo queda vacío y
        // TLS falla; este override lo resuelve. Opcional.
        'tls_cacert' => env('SYNC_TLS_CACERT'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        // Secret token que Telegram envia en el header X-Telegram-Bot-Api-Secret-Token
        // al llamar al webhook. Valida que el update viene de Telegram.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    'local_support' => [
        // Esta consola solamente se habilita para instalaciones locales. Sus
        // rutas tambien exigen que el request llegue desde loopback.
        'enabled' => (bool) env('LOCAL_TECHNICAL_CONSOLE_ENABLED', false),
        'cloud_url' => env('LOCAL_TECHNICAL_CONSOLE_CLOUD_URL', env('SYNC_CLOUD_URL')),
        'service_mode' => (bool) env('INVENTARIO_SERVICE_MODE', false),
    ],

    'crm' => [
        'rate_limit_per_minute' => (int) env('CRM_RATE_LIMIT_PER_MINUTE', 60),
        'stock_stale_after_minutes' => (int) env('CRM_STOCK_STALE_AFTER_MINUTES', 30),
    ],

];
