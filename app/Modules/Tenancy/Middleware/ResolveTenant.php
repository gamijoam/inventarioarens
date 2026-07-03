<?php

namespace App\Modules\Tenancy\Middleware;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Auth\Models\AuthToken;
use App\Support\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly TenantManager $tenants)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);

        abort_unless($tenant, 404, 'Tenant not found.');

        $token = $request->attributes->get('auth_token');

        if ($token instanceof AuthToken) {
            abort_unless($token->tenant_id === $tenant->id, 403, 'Token does not belong to this tenant.');
        }

        if ($request->user()) {
            abort_unless(
                $request->user()->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists(),
                403,
                'User does not belong to this tenant.'
            );
        }

        $this->tenants->set($tenant);

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant->id);
        }

        try {
            return $next($request);
        } finally {
            $this->tenants->clear();
        }
    }

    private function resolveTenant(Request $request): ?Tenant
    {
        $identifier = $request->header('X-Tenant')
            ?? $request->route('tenant')
            ?? $request->query('tenant');

        if ($identifier) {
            return Tenant::query()
                ->where('slug', $identifier)
                ->orWhere('domain', $identifier)
                ->first();
        }

        return Tenant::query()
            ->where('domain', $request->getHost())
            ->first();
    }
}
