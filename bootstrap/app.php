<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Auth\Middleware\AuthenticateApiToken;
use App\Modules\Auth\Services\CookieIssuer;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidStockQuantityException;
use App\Modules\Tenancy\Middleware\ResolveTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$secretFiles = [
    'APP_KEY' => getenv('INVENTARIO_APP_KEY_FILE') ?: null,
    'APP_BOOTSTRAP_TOKEN' => getenv('INVENTARIO_BOOTSTRAP_TOKEN_FILE') ?: null,
];
foreach ($secretFiles as $environmentName => $secretFile) {
    if (getenv($environmentName) !== false || ! is_string($secretFile) || ! is_readable($secretFile)) {
        continue;
    }

    $secret = trim((string) file_get_contents($secretFile));
    if ($environmentName === 'APP_KEY' && ! str_starts_with($secret, 'base64:')) {
        $secret = 'base64:'.$secret;
    }
    if ($secret !== '') {
        putenv($environmentName.'='.$secret);
    }
}

$storagePath = getenv('LARAVEL_STORAGE_PATH') ?: null;
$environmentFile = dirname(__DIR__).'/.env';
if ($storagePath === null && is_readable($environmentFile)) {
    $environment = (string) file_get_contents($environmentFile);
    if (preg_match('/^\s*LARAVEL_STORAGE_PATH\s*=\s*(.+?)\s*$/m', $environment, $matches) === 1) {
        $storagePath = trim($matches[1], " \t\n\r\0\x0B\"'");
    }
}

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Modules/AccessControl/Commands',
        __DIR__.'/../app/Modules/Sync/Commands',
        __DIR__.'/../app/Console/Commands',
        __DIR__.'/../app/Modules/DataImport/Commands',
        __DIR__.'/../app/Modules/TelegramBot/Console',
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('images:download --limit=20')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/images-download.log'));

        $schedule->command('imports:cleanup --days=30')
            ->dailyAt('03:00')
            ->withoutOverlapping(30)
            ->appendOutputTo(storage_path('logs/imports-cleanup.log'));

        $schedule->command('inventory:reconcile')
            ->dailyAt('02:30')
            ->withoutOverlapping(30)
            ->appendOutputTo(storage_path('logs/inventory-reconcile.log'));

        // Bot de Telegram: alertas de stock bajo y resumen diario. Correr cada
        // hora; el comando evalua la hora configurada por cada empresa.
        $schedule->command('telegram:alerts --type=stock')
            ->hourly()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/telegram-alerts.log'));

        $schedule->command('telegram:alerts --type=resumen')
            ->hourly()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/telegram-resumen.log'));
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->append(AssignRequestId::class);

        $middleware->alias([
            'api.auth' => AuthenticateApiToken::class,
            'tenant' => ResolveTenant::class,
            'idempotency' => IdempotencyKey::class,
        ]);

        // Excluir la cookie de auth del cifrado automatico de Laravel.
        // La cookie ya es httpOnly (mitigacion XSS) y el navegador la
        // transmite tal cual; encriptarla anade complejidad sin beneficio
        // practico. Ademas, el sync worker y Postman envian Bearer header
        // (no cookie), asi que este cambio no los afecta.
        // Ver docs/AUTH_COOKIE_API.md seccion "Cifrado de cookies".
        EncryptCookies::except([
            CookieIssuer::COOKIE_NAME,
        ]);

        // El webhook de Telegram no envia cookies ni token CSRF (es un
        // request de servidor a servidor). La autenticidad se valida por el
        // header X-Telegram-Bot-Api-Secret-Token en el controller.
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Errores de inventario -> 422 amigable, no 500.
        $exceptions->render(function (InsufficientStockException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Stock insuficiente para esta operación de inventario.',
                    'errors' => [
                        'quantity' => ['No hay suficiente stock disponible para el movimiento.'],
                    ],
                ], 422);
            }

            return null;
        });

        $exceptions->render(function (InvalidStockQuantityException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'La cantidad de inventario debe ser mayor que cero.',
                    'errors' => [
                        'quantity' => ['La cantidad debe ser mayor que cero.'],
                    ],
                ], 422);
            }

            return null;
        });
    })->create();

if ($storagePath !== null && $storagePath !== '') {
    $app->useStoragePath($storagePath);
}

return $app;
