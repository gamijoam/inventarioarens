<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }

        if ($this->isApiRequest($request) || $this->isAssetRequest($request)) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        } else {
            $csp = $this->buildWebCsp();
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }

    private function isApiRequest(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private function isAssetRequest(Request $request): bool
    {
        $path = $request->path();

        return str_starts_with($path, 'build/')
            || str_starts_with($path, 'storage/')
            || str_ends_with($path, '.js')
            || str_ends_with($path, '.css')
            || str_ends_with($path, '.woff')
            || str_ends_with($path, '.woff2')
            || str_ends_with($path, '.png')
            || str_ends_with($path, '.svg')
            || str_ends_with($path, '.ico');
    }

    private function buildWebCsp(): string
    {
        $viteClient = "'self'";
        $viteHmr = 'ws://localhost:5173 http://localhost:5173';

        if (app()->environment('local')) {
            $script = "'self' 'unsafe-inline' 'unsafe-eval' {$viteHmr}";
            $style = "'self' 'unsafe-inline'";
            $connect = "'self' {$viteHmr}";
        } else {
            $script = "'self'";
            $style = "'self' 'unsafe-inline'";
            $connect = "'self' https://app.miinventariofacil.com";
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src {$style}",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src {$connect}",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
