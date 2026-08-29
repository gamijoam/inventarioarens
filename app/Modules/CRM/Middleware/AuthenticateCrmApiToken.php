<?php

namespace App\Modules\CRM\Middleware;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\CRM\Models\CrmApiToken;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCrmApiToken
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();

        abort_unless(
            is_string($plainToken) && str_starts_with($plainToken, 'crm_'),
            Response::HTTP_UNAUTHORIZED,
            'Credencial CRM ausente o inválida.',
            ['WWW-Authenticate' => 'Bearer realm="crm", error="invalid_token"'],
        );

        $token = CrmApiToken::withoutGlobalScopes()
            ->with('tenant')
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        abort_unless(
            $token instanceof CrmApiToken && $token->tenant,
            Response::HTTP_UNAUTHORIZED,
            'Credencial CRM ausente o inválida.',
            ['WWW-Authenticate' => 'Bearer realm="crm", error="invalid_token"'],
        );

        $this->assertOptionalTenantHeader($request, $token->tenant);
        $this->tenants->set($token->tenant);
        $request->attributes->set('crm_token', $token);
        $request->attributes->set('auth_token_source', 'bearer');

        if ($token->last_used_at === null || $token->last_used_at->lte(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        try {
            $response = $next($request);

            $this->audit->record(
                action: 'crm.api.access',
                newValues: [
                    'token_id' => $token->id,
                    'token_prefix' => $token->token_prefix,
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'status' => $response->getStatusCode(),
                ],
            );

            return $response;
        } finally {
            $this->tenants->clear();
        }
    }

    private function assertOptionalTenantHeader(Request $request, Tenant $tokenTenant): void
    {
        $identifier = $request->header('X-Tenant');
        if (! is_string($identifier) || trim($identifier) === '') {
            return;
        }

        $headerTenant = Tenant::query()
            ->where(function ($query) use ($identifier): void {
                $query->where('slug', $identifier)->orWhere('domain', $identifier);
            })
            ->first();

        abort_unless(
            $headerTenant,
            Response::HTTP_NOT_FOUND,
            'Tenant not found.',
        );

        abort_unless(
            (int) $headerTenant->id === (int) $tokenTenant->id,
            Response::HTTP_FORBIDDEN,
            'La credencial CRM no pertenece al tenant solicitado.',
        );
    }
}
