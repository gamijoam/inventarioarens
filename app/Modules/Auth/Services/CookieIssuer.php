<?php

namespace App\Modules\Auth\Services;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emisor y limpiador de la cookie de sesion httpOnly.
 *
 * Decisiones de diseno:
 *
 * - httpOnly=true: el JS del navegador NO puede leer la cookie. Esto mitiga
 *   robo de tokens por XSS (cross-site scripting). Es la diferencia clave
 *   respecto a localStorage: aunque un atacante inyecte <script>, no puede
 *   exfiltrar el token porque JS no tiene acceso a cookies httpOnly.
 *
 * - Secure: la cookie SOLO se envia por HTTPS. Se setea true cuando el request
 *   actual es seguro (HTTPS) o cuando APP_ENV=production. En dev local con
 *   HTTP, se setea false para que el navegador la envie.
 *
 * - SameSite=Lax: la cookie NO se envia en requests cross-site (ej: un sitio
 *   malicioso haciendo un <form action="https://tu-api">). PERO SI se envia
 *   en navegacion top-level (link clickeado). Esto es el balance correcto
 *   para SaaS: bloquea CSRF manteniendo UX de links compartidos.
 *   (Strict seria mas seguro pero rompe links externos que llevan al SaaS.)
 *
 * - Path=/: la cookie aplica a toda la API.
 *
 * - Lifetime=30 dias: alineado con la expiracion del AuthToken en DB.
 *
 * CSRF adicional: ademas de SameSite=Lax, el middleware AuthenticateApiToken
 * exige header `X-Requested-With: XMLHttpRequest` para requests autenticados
 * via cookie. Esto bloquea form submissions cross-site que NO usan AJAX
 * (SameSite=Lax ya cubre los casos principales, pero este header es
 * defensa en profundidad).
 *
 * Ver docs/AUTH_COOKIE_API.md para el contrato completo y la guia de
 * integracion del frontend.
 */
class CookieIssuer
{
    public const COOKIE_NAME = 'auth_token';

    public const LIFETIME_MINUTES = 60 * 24 * 30; // 30 dias.

    /**
     * Emite la cookie de sesion en el response.
     *
     * @param  string  $plainToken  El token en texto plano (NO el hash).
     *                              El navegador lo recibira como valor de la cookie.
     */
    public function issueAuthToken(Response $response, string $plainToken): void
    {
        $response->headers->setCookie($this->buildCookie($plainToken));
    }

    /**
     * Limpia la cookie de sesion del response. Llamar en logout y en
     * situaciones donde la cookie deba invalidarse (ej: rotacion).
     */
    public function clearAuthToken(Response $response): void
    {
        $response->headers->setCookie(Cookie::create(self::COOKIE_NAME)
            ->withValue('')
            ->withExpires(0)
            ->withPath('/')
            ->withHttpOnly()
            ->withSameSite('lax'));
    }

    /**
     * Rota la cookie: limpia la anterior y emite una nueva. Usado en
     * switch-tenant (donde queremos invalidar el token anterior y emitir uno nuevo).
     */
    public function rotateAuthToken(Response $response, string $newPlainToken): void
    {
        $this->clearAuthToken($response);
        $this->issueAuthToken($response, $newPlainToken);
    }

    private function buildCookie(string $plainToken): Cookie
    {
        $cookie = Cookie::create(self::COOKIE_NAME)
            ->withValue($plainToken)
            ->withPath('/')
            ->withHttpOnly()
            ->withSameSite('lax')
            ->withExpires(time() + self::LIFETIME_MINUTES);

        // Secure solo cuando el contexto lo permite (HTTPS en prod, o
        // cuando APP_FORCE_SECURE_COOKIES=true en dev con proxy HTTPS).
        if ($this->shouldUseSecure()) {
            $cookie = $cookie->withSecure();
        }

        return $cookie;
    }

    private function shouldUseSecure(): bool
    {
        // En produccion siempre forzamos Secure.
        if (app()->environment('production')) {
            return true;
        }

        // En dev/staging, respetamos APP_FORCE_SECURE_COOKIES para casos
        // donde el dev usa un proxy HTTPS tipo ngrok o caddy.
        return (bool) env('APP_FORCE_SECURE_COOKIES', false);
    }
}
