<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Auth\Middleware\AuthenticateApiToken;
use App\Modules\Auth\Services\CookieIssuer;
use App\Modules\Tenancy\Middleware\ResolveTenant;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
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
    ])
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('images:download --limit=20')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/images-download.log'));

        $schedule->command('imports:cleanup --days=30')
            ->dailyAt('03:00')
            ->withoutOverlapping(30)
            ->appendOutputTo(storage_path('logs/imports-cleanup.log'));
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
