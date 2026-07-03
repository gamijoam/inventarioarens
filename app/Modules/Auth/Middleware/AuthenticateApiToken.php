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

        abort_unless($plainToken, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $token = AuthToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        abort_unless($token?->user, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $token->forceFill(['last_used_at' => now()])->save();

        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('auth_token', $token);

        return $next($request);
    }
}
