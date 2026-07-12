<?php

namespace App\Modules\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->isPlatformAdmin(), 403, 'Platform admin access required.');

        return $next($request);
    }
}