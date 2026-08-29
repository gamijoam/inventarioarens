<?php

namespace App\Modules\Tenancy\Middleware;

use App\Modules\Tenancy\Services\TenantCapabilityService;
use App\Support\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantCapability
{
    public function __construct(
        private readonly TenantCapabilityService $capabilities,
        private readonly TenantManager $tenants,
    ) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $tenant = $this->tenants->require();

        abort_unless(
            $this->capabilities->enabled($tenant, $capability),
            Response::HTTP_FORBIDDEN,
            "La capacidad '{$capability}' no esta habilitada para esta empresa.",
        );

        return $next($request);
    }
}
