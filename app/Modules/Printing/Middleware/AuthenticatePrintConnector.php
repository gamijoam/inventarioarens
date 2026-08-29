<?php

namespace App\Modules\Printing\Middleware;

use App\Modules\Printing\Models\PrintConnector;
use App\Modules\Printing\Models\PrintConnectorToken;
use App\Support\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePrintConnector
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();
        abort_unless($plainToken, Response::HTTP_UNAUTHORIZED, 'Conector no autenticado.');

        $token = PrintConnectorToken::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
        $connector = $token
            ? PrintConnector::withoutGlobalScopes()->with('tenant')->find($token->print_connector_id)
            : null;

        abort_unless($connector?->isActive(), Response::HTTP_UNAUTHORIZED, 'Credencial de conector invalida.');

        $token->update(['last_used_at' => now()]);
        $connector->update(['last_seen_at' => now()]);

        $tenancy = app(TenantManager::class);
        $tenancy->set($connector->tenant);
        $request->attributes->set('print_connector', $connector);
        $request->attributes->set('print_connector_token', $token);

        try {
            return $next($request);
        } finally {
            $tenancy->clear();
        }
    }
}
