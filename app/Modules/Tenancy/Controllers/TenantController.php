<?php

namespace App\Modules\Tenancy\Controllers;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Requests\StoreTenantRequest;
use App\Modules\Tenancy\Requests\UpdateTenantRequest;
use App\Modules\Tenancy\Resources\TenantResource;
use App\Modules\Tenancy\Services\TenantRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class TenantController extends Controller
{
    public function __construct(private readonly TenantRegistrationService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'tenants.view');

        $tenants = Tenant::query()
            ->withCount('users')
            ->orderBy('name')
            ->paginate(25);

        return TenantResource::collection($tenants);
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $this->authorizePermission($request, 'tenants.create');

        $tenant = $this->service->register($request->validated(), $request->user());
        $tenant->loadCount('users');

        return TenantResource::make($tenant)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Tenant $tenant): TenantResource
    {
        $this->authorizePermission($request, 'tenants.view');
        $tenant->loadCount('users');

        return TenantResource::make($tenant);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        $this->authorizePermission($request, 'tenants.update');

        $tenant = $this->service->update($tenant, $request->validated(), $request->user());
        $tenant->loadCount('users');

        return TenantResource::make($tenant);
    }

    public function destroy(Request $request, Tenant $tenant): Response
    {
        $this->authorizePermission($request, 'tenants.delete');

        $this->service->deactivate($tenant, $request->user());

        return response()->noContent();
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), Response::HTTP_FORBIDDEN);
    }
}