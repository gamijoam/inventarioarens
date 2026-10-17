<?php

namespace App\Modules\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMcpPlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $allowedEmail = strtolower((string) config('mcp.allowed_admin_email'));

        abort_unless(
            $user?->isPlatformAdmin() && strtolower($user->email) === $allowedEmail,
            Response::HTTP_FORBIDDEN,
            'No tiene acceso al MCP de BalanzaPro.'
        );

        return $next($request);
    }
}
