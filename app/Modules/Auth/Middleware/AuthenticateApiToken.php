<?php

namespace App\Modules\Auth\Middleware;

use App\Modules\Auth\Models\AuthToken;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();

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

        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('auth_token', $token);

        return $next($request);
    }

    /**
     * Throttle writes a last_used_at: solo actualiza si han pasado
     * mas de 5 minutos desde la ultima escritura. Esto evita 1 UPDATE por
     * request autenticado, lo que importa para cargas altas en el WPF/POS
     * (polling cada 15-30s) y reduce write amplification a la DB.
     */
    private function touchLastUsedAtIfStale(\App\Modules\Auth\Models\AuthToken $token): void
    {
        if ($token->last_used_at && $token->last_used_at->gt(now()->subMinutes(5))) {
            return;
        }

        $token->forceFill(['last_used_at' => now()])->save();
    }
}
