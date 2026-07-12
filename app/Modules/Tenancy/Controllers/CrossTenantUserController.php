<?php

namespace App\Modules\Tenancy\Controllers;

use App\Models\User;
use App\Modules\AccessControl\Resources\TenantUserResource;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Requests\AttachUserToTenantRequest;
use App\Modules\Tenancy\Services\CrossTenantUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CrossTenantUserController extends Controller
{
    public function __construct(private readonly CrossTenantUserService $service)
    {
    }

    public function index(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'tenants.view');

        return TenantUserResource::collection($this->service->listUsers($tenant));
    }

    public function store(AttachUserToTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorizePermission($request, 'tenants.users.attach');

        $user = $this->service->attachUser($tenant, $request->validated(), $request->user());

        return TenantUserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Tenant $tenant, User $user): Response
    {
        $this->authorizePermission($request, 'tenants.users.detach');

        $this->service->detachUser($tenant, $user, $request->user());

        return response()->noContent();
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), Response::HTTP_FORBIDDEN);
    }
}