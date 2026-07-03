<?php

namespace App\Modules\AccessControl\Controllers;

use App\Modules\AccessControl\Requests\StoreTenantUserRequest;
use App\Modules\AccessControl\Requests\UpdateTenantUserRequest;
use App\Modules\AccessControl\Requests\UpdateTenantUserRolesRequest;
use App\Modules\AccessControl\Requests\UpdateTenantUserStatusRequest;
use App\Modules\AccessControl\Resources\TenantUserResource;
use App\Modules\AccessControl\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class TenantUserController extends Controller
{
    public function __construct(private readonly AccessControlService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'users.view');

        return TenantUserResource::collection($this->service->tenantUsers()->paginate(25));
    }

    public function store(StoreTenantUserRequest $request): JsonResponse
    {
        $this->authorizePermission($request, 'users.create');

        return TenantUserResource::make(
            $this->service->createOrAttachUser($request->validated())
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, int $tenantUser): TenantUserResource
    {
        $this->authorizePermission($request, 'users.view');

        return TenantUserResource::make($this->service->tenantUser($tenantUser));
    }

    public function update(UpdateTenantUserRequest $request, int $tenantUser): TenantUserResource
    {
        $this->authorizePermission($request, 'users.update');

        return TenantUserResource::make(
            $this->service->updateUser($this->service->tenantUser($tenantUser), $request->validated())
        );
    }

    public function status(UpdateTenantUserStatusRequest $request, int $tenantUser): TenantUserResource
    {
        $this->authorizePermission($request, 'users.update');

        return TenantUserResource::make(
            $this->service->updateStatus(
                $this->service->tenantUser($tenantUser),
                $request->validated('status'),
                $request->user()
            )
        );
    }

    public function roles(UpdateTenantUserRolesRequest $request, int $tenantUser): TenantUserResource
    {
        $this->authorizePermission($request, 'users.update');

        return TenantUserResource::make(
            $this->service->updateUserRoles($this->service->tenantUser($tenantUser), $request->validated('roles'))
        );
    }

    public function permissions(Request $request, int $tenantUser): JsonResponse
    {
        $this->authorizePermission($request, 'users.view');
        $user = $this->service->tenantUser($tenantUser);

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'permissions' => $this->service->userPermissions($user),
            ],
        ]);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), Response::HTTP_FORBIDDEN);
    }
}
