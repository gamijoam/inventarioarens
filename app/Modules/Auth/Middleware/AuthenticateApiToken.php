<?php

namespace App\Modules\Auth\Middleware;

use App\Modules\Auth\Models\AuthToken;
use App\Modules\Auth\Services\CookieIssuer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica el request contra la tabla `auth_tokens`.
 *
 * Acepta el token en DOS formatos (en orden de prioridad):
 *
 * 1. `Authorization: Bearer <token>` (header).
 *    Usado por: sync worker (scripts/sync-worker), Postman, scripts PHP,
 *    integraciones externas. Es el formato "API" clasico.
 *
 * 2. Cookie httpOnly `auth_token=<token>`.
 *    Usado por: el frontend SPA (navegador). El navegador la envia
 *    automaticamente. Es el formato "SaaS" moderno, mas seguro contra XSS
 *    porque JS no puede leer cookies httpOnly.
 *
 * CSRF protection:
 * Cuando el request se autentica via COOKIE (no Bearer), exigimos:
 *   - Header `X-Requested-With: XMLHttpRequest` (los formularios HTML
 *     nativos no pueden setear este header).
 *   - Header `Origin` debe coincidir con `config('app.url')` (defensa
 *     adicional contra cross-site).
 * Requests autenticados via Bearer NO requieren esto (porque el token NO
 * se envia automaticamente, no hay riesgo CSRF).
 *
 * Cuando se autentica via cookie pero el request falla la validacion CSRF,
 * respondemos 403 con `error=csrf_required` para que el frontend pueda
 * distinguir entre "no estas autenticado" y "tu request fue bloqueada por CSRF".
 *
 * Ver docs/AUTH_COOKIE_API.md para el contrato completo.
 */
class AuthenticateApiToken
{
    /**
     * Fuente del token en el request actual. Se setea en handle() y queda
     * disponible via getTokenSource() para que los controllers puedan
     * actuar diferente segun el origen (ej: switch-tenant rota solo la
     * cookie si el user esta autenticado via cookie).
     */
    public const SOURCE_BEARER = 'bearer';

    public const SOURCE_COOKIE = 'cookie';

public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('auth_token_source', null);

        $plainToken = $request->bearerToken();

        if ($plainToken) {
            $request->attributes->set('auth_token_source', self::SOURCE_BEARER);
        } else {
            $plainToken = $request->cookie(CookieIssuer::COOKIE_NAME);

            if ($plainToken) {
                $this->assertCsrfProtection($request);
                $request->attributes->set('auth_token_source', self::SOURCE_COOKIE);
            }
        }

        // Fallback: si ya hay un user autenticado por otra via (ej: tests
        // que usan $this->actingAs()), dejamos pasar.
        if (! $plainToken && $request->user()) {
            return $next($request);
        }

        abort_unless($plainToken, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.', [
            'WWW-Authenticate' => 'Bearer realm="api"',
        ]);

        $token = AuthToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        abort_unless($token?->user, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.', [
            'WWW-Authenticate' => 'Bearer realm="api", error="invalid_token"',
        ]);

        $this->touchLastUsedAtIfStale($token);

        auth()->setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('auth_token', $token);

        return $next($request);
    }

    /**
     * Valida que el request autenticado via cookie cumpla los requisitos
     * anti-CSRF. Si no, aborta con 403.
     *
     * Requisitos (ambos deben cumplirse):
     *   1. Header `X-Requested-With: XMLHttpRequest`.
     *      Los <form> HTML nativos no pueden setear este header, asi que
     *      cualquier ataque CSRF basado en form-submission fallara aqui.
     *      Requests AJAX de fetch/axios siempre lo setean (lo seteamos
     *      por defecto en el cliente HTTP).
     *   2. Header `Origin` (o `Referer` como fallback) que coincida con
     *      el origin configurado en `app.url`. Esto bloquea requests
     *      cross-origin que SII podrian setear el header X-Requested-With
     *      via JavaScript (ej: si el atacante tiene un XSS en otro origen).
     */
    private function assertCsrfProtection(Request $request): void
    {
        $xRequestedWith = $request->header('X-Requested-With');
        if ($xRequestedWith !== 'XMLHttpRequest') {
            abort(403, 'CSRF: X-Requested-With header missing or invalid.', [
                'WWW-Authenticate' => 'Bearer realm="api", error="csrf_required"',
            ]);
        }

        $expectedOrigin = $this->extractOriginFromAppUrl();
        if ($expectedOrigin === null) {
            // Si no podemos determinar el origin esperado, dejamos pasar.
            // Esto ocurre solo en tests o setups extremos. En prod, app.url
            // SIEMPRE esta definido.
            return;
        }

        $actualOrigin = $request->header('Origin') ?? $request->header('Referer');
        if ($actualOrigin === null) {
            abort(403, 'CSRF: Origin header missing.', [
                'WWW-Authenticate' => 'Bearer realm="api", error="csrf_required"',
            ]);
        }

        // Comparamos solo el origin (scheme + host + port), no el path.
        $actualOriginNormalized = $this->extractOriginFromUrl((string) $actualOrigin);
        if ($actualOriginNormalized !== $expectedOrigin) {
            abort(403, 'CSRF: Origin mismatch.', [
                'WWW-Authenticate' => 'Bearer realm="api", error="csrf_required"',
            ]);
        }
    }

    private function extractOriginFromAppUrl(): ?string
    {
        $appUrl = (string) config('app.url', '');
        if ($appUrl === '') {
            return null;
        }

        return $this->extractOriginFromUrl($appUrl);
    }

    private function extractOriginFromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    /**
     * Throttle writes a last_used_at: solo actualiza si han pasado
     * mas de 5 minutos desde la ultima escritura. Esto evita 1 UPDATE por
     * request autenticado, lo que importa para cargas altas en el WPF/POS
     * (polling cada 15-30s) y reduce write amplification a la DB.
     */
    private function touchLastUsedAtIfStale(AuthToken $token): void
    {
        if ($token->last_used_at && $token->last_used_at->gt(now()->subMinutes(5))) {
            return;
        }

        $token->forceFill(['last_used_at' => now()])->save();
    }
}