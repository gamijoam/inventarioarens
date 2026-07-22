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
                // CSRF solo aplica a metodos que mutan estado. GET / HEAD /
                // OPTIONS son safe segun RFC 7231 y no requieren proteccion
                // CSRF (un atacante no puede causar un side-effect via GET
                // porque los browsers no envian cookies cross-origin sin
                // CORS, y la proteccion CSRF existe justamente para
                // bloquear side-effects cross-origin).
                //
                // Esto permite que links <a href="/api/import/templates/x"
                // download> y window.open(...) funcionen directamente desde
                // el frontend sin necesidad de fetch manual con axios.
                if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                    $this->assertCsrfProtection($request);
                }
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
     *   2. Header `Origin` (o `Referer` como fallback) que este en la lista
     *      `app.allowed_origins_for_csrf` (config/app.php). Esto bloquea
     *      requests cross-origin que SI podrian setear el header X-Requested-With
     *      via JavaScript (ej: si el atacante tiene un XSS en otro origen).
     *
     *      En produccion, esta lista deberia contener SOLO el origin publico
     *      del SaaS (ej: "https://app.miinventariofacil.com"). En desarrollo
     *      se incluyen los puertos comunes de Vite (5173) y otros.
     *
     *      Si `app.allowed_origins_for_csrf` esta vacio, se hace fallback a
     *      `app.url` (single-origin check), lo cual es estricto pero seguro.
     *      Configurar via .env: APP_ALLOWED_ORIGINS_FOR_CSRF="origin1,origin2".
     */
    private function assertCsrfProtection(Request $request): void
    {
        // Check 1: X-Requested-With: XMLHttpRequest es obligatorio.
        // Los <form> HTML nativos no pueden setear este header, asi que cualquier
        // ataque CSRF basado en form-submission fallara aqui.
        // Requests AJAX de fetch/axios siempre lo setean.
        $xRequestedWith = $request->header('X-Requested-With');
        if ($xRequestedWith !== 'XMLHttpRequest') {
            abort(403, 'CSRF: X-Requested-With header missing or invalid.', [
                'WWW-Authenticate' => 'Bearer realm="api", error="csrf_required"',
            ]);
        }

        // Check 2: Origin/Referer. Si NO esta presente, la peticion es
        // same-origin desde el punto de vista del navegador (ej: Vite dev
        // server reenvia /api/* al backend sin reescribir el Host, asi que
        // el browser no envia Origin). Confiamos en X-Requested-With como
        // segunda linea de defensa (no se puede setear desde un <form> HTML).
        $actualOrigin = $request->header('Origin') ?? $request->header('Referer');
        if ($actualOrigin === null) {
            // Misma proteccion anti-CSRF (X-Requested-With) sigue activa.
            // Si el atacante quisiera bypassear esto necesitaria ejecutar
            // JavaScript en el origen, lo cual es XSS (out of scope para CSRF).
            return;
        }

        // Check 3: si hay Origin/Referer, validar contra allowlist
        // (proteccion cross-origin). Sin esto, un sitio atacante podria
        // hacer fetch con credentials si la cookie no fuera SameSite=Strict.
        $allowedOrigins = $this->getAllowedOrigins();
        if ($allowedOrigins === []) {
            // Sin origins permitidos configurados = no podemos validar cross-origin.
            // Bloqueamos por seguridad (deberia estar configurado siempre).
            abort(403, 'CSRF: no allowed origins configured.', [
                'WWW-Authenticate' => 'Bearer realm="api", error="csrf_required"',
            ]);
        }

        $actualOriginNormalized = $this->extractOriginFromUrl((string) $actualOrigin);
        if ($actualOriginNormalized === null) {
            abort(403, 'CSRF: Origin malformed.', [
                'WWW-Authenticate' => 'Bearer realm="api", error="csrf_required"',
            ]);
        }

        if (! in_array($actualOriginNormalized, $allowedOrigins, true)) {
            abort(403, 'CSRF: Origin not in allowlist.', [
                'WWW-Authenticate' => 'Bearer realm="api", error="csrf_required"',
            ]);
        }
    }

    /**
     * Retorna la lista de origins permitidos para CSRF.
     *
     * Prioridad:
     *   1. `app.allowed_origins_for_csrf` (CSV desde .env) si tiene valores.
     *   2. `app.url` (fallback single-origin si la lista esta vacia).
     *
     * Cada entry se normaliza a "scheme://host[:port]" para comparacion exacta.
     */
    private function getAllowedOrigins(): array
    {
        $raw = config('app.allowed_origins_for_csrf', []);
        if (is_array($raw) && $raw !== []) {
            $normalized = [];
            foreach ($raw as $origin) {
                $n = $this->extractOriginFromUrl((string) $origin);
                if ($n !== null) {
                    $normalized[] = $n;
                }
            }

            return array_values(array_unique($normalized));
        }

        // Fallback a app.url (single-origin estricto).
        $fallback = $this->extractOriginFromUrl((string) config('app.url', ''));

        return $fallback !== null ? [$fallback] : [];
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
